<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\PaymentAttempt;
use App\Models\Student;
use App\Models\TrialClass;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Booking rules, in one of two modes (config/booking.php, BOOKING_MODE):
 *  - hold:         creating a booking holds the seat for BOOKING_HOLD_MINUTES; unpaid holds expire (lazily, under the
 *                  class lock) and free the seat. The race is decided at booking time, so losers are never charged.
 *  - first_to_pay: creating a booking reserves nothing; pay() confirms only if a seat is still free, else refunds.
 * In both modes a payment that succeeds after its seat is gone is refunded (the vendor already took the money).
 *
 * Locking: every transaction locks the class row FIRST (serialises seat decisions per class and avoids a stale
 * REPEATABLE READ snapshot), then re-reads the booking with a lock. The gateway is never called inside a transaction.
 */
class BookingService
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Admin: all bookings. Teacher: bookings in own classes. Parent: own children's history.
     * Filters are applied after the role scope, so they can only narrow it, never widen it.
     */
    public function list(User $actor, array $filters): Collection
    {
        return Booking::with(['student.guardian.user', 'trialClass', 'paymentAttempts'])
            ->when($actor->role === UserRole::Teacher, fn ($q) => $q->whereHas(
                'trialClass',
                fn ($c) => $c->where('teacher_id', $actor->teacher_id),
            ))
            ->when($actor->role === UserRole::Parent, fn ($q) => $q->whereHas(
                'student',
                fn ($s) => $s->where('parent_id', $actor->parent_id),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['trial_class_id'] ?? null, fn ($q, $id) => $q->where('trial_class_id', $id))
            ->when($filters['student_id'] ?? null, fn ($q, $id) => $q->where('student_id', $id))
            ->when($filters['parent_id'] ?? null, fn ($q, $id) => $q->whereHas('student', fn ($s) => $s->where('parent_id', $id)))
            ->latest('id')
            ->get();
    }

    /** Returns the child's existing pending booking instead of a new one. A pending booking does not take a seat. */
    public function createBooking(int $parentId, int $studentId, int $trialClassId): Booking
    {
        return DB::transaction(function () use ($parentId, $studentId, $trialClassId) {
            $class = $this->lockClass($trialClassId);
            $this->expireHolds($trialClassId);
            $student = Student::findOrFail($studentId);

            if ($student->parent_id !== $parentId) {
                throw BookingException::studentNotOwned();
            }
            if ($class->starts_at->isPast()) {
                throw BookingException::classStarted();
            }

            $existing = Booking::where('student_id', $studentId)
                ->where('trial_class_id', $trialClassId)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])
                ->lockForUpdate()
                ->get();

            if ($existing->contains('status', BookingStatus::Confirmed)) {
                throw BookingException::alreadyBooked();
            }
            if ($pending = $existing->first()) {
                return $pending;                               // hold mode: still within its hold (expired ones are EXPIRED now)
            }
            if ($this->seatsInUse($class->id) >= $class->capacity) {
                throw BookingException::classFull();
            }

            $booking = Booking::create([
                'student_id' => $studentId,
                'trial_class_id' => $trialClassId,
                'status' => BookingStatus::PendingPayment,
            ]);
            $this->log('booking.created', $booking);

            return $booking;
        }, 3);
    }

    public function pay(Booking $booking, string $idempotencyKey, string $simulate = 'success', int $delayMs = 0): Booking
    {
        // Phase 0: idempotency. A key that was already used returns the current state, never charges again.
        $existing = PaymentAttempt::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->booking_id !== $booking->id) {
                throw BookingException::idempotencyKeyReused();
            }

            return $booking->fresh();
        }

        // Phase 1: pre-check under lock. Reject WITHOUT charging if the seat is already gone.
        $rejectReason = DB::transaction(function () use ($booking) {
            $class = $this->lockClass($booking->trial_class_id);
            $this->expireHolds($class->id);
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Expired) {
                return 'hold_expired';                         // seat already released: don't send to the vendor
            }
            if ($locked->status !== BookingStatus::PendingPayment) {
                return 'booking_closed';                       // already final: nothing to do
            }
            if ($this->studentAlreadyConfirmed($locked)) {
                $locked->update(['status' => BookingStatus::Cancelled]);

                return 'duplicate_booking';
            }
            if ($this->seatsInUse($class->id, $locked->id) >= $class->capacity) {
                $locked->update(['status' => BookingStatus::FailedClassFull]);

                return 'class_full';
            }

            return null;
        }, 3);

        if ($rejectReason !== null) {
            $this->log('booking.rejected_before_charge', $booking, ['reason' => $rejectReason]);

            return $booking->fresh();                          // no payment attempt recorded
        }

        // Phase 2: claim the idempotency key, then charge OUTSIDE any lock.
        try {
            $attempt = PaymentAttempt::create([
                'booking_id' => $booking->id,
                'status' => PaymentStatus::Pending,
                'amount_cents' => $booking->trialClass->price_cents,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the claim: never charge twice.
            $other = PaymentAttempt::where('idempotency_key', $idempotencyKey)->firstOrFail();
            if ($other->booking_id !== $booking->id) {
                throw BookingException::idempotencyKeyReused();
            }

            return $booking->fresh();
        }

        $result = $this->gateway->charge($attempt->amount_cents, $simulate, $delayMs);

        if (! $result->success) {
            $attempt->update(['status' => PaymentStatus::Failed, 'failure_reason' => $result->failureReason ?? 'card_declined']);
            Booking::whereKey($booking->id)
                ->where('status', BookingStatus::PendingPayment)   // guarded: only a pending booking can fail
                ->update(['status' => BookingStatus::PaymentFailed]);
            $this->log('booking.payment_failed', $booking, ['reason' => $attempt->failure_reason]);

            return $booking->fresh();
        }

        $attempt->update(['status' => PaymentStatus::Succeeded]);

        // Phase 3: final check under lock. Confirm only if a seat is STILL free.
        $refundReason = DB::transaction(function () use ($booking) {
            $class = $this->lockClass($booking->trial_class_id);
            $this->expireHolds($class->id, $booking->id);
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            // EXPIRED here = the hold ran out while the vendor was charging. The money is taken, so it is decided like
            // a pending booking: confirm if a seat is still free, otherwise refund.
            if (! in_array($locked->status, [BookingStatus::PendingPayment, BookingStatus::Expired], true)) {
                return 'booking_closed';                       // cancelled, or confirmed by another payment
            }
            if ($this->studentAlreadyConfirmed($locked)) {
                $locked->update(['status' => BookingStatus::Cancelled]);

                return 'duplicate_booking';
            }
            if ($this->seatsInUse($class->id, $locked->id) >= $class->capacity) {
                $locked->update(['status' => BookingStatus::FailedClassFull]);

                return 'class_full';
            }

            $locked->update(['status' => BookingStatus::Confirmed, 'confirmed_at' => now()]);

            return null;
        }, 3);

        if ($refundReason !== null) {
            $this->refund($booking, $attempt, $refundReason);   // outside the transaction
        } else {
            $this->log('booking.confirmed', $booking);
        }

        return $booking->fresh();
    }

    public function cancel(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $this->lockClass($booking->trial_class_id);
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::PendingPayment) {
                throw BookingException::notCancellable();
            }

            // A payment in flight for this booking will see 'booking_closed' in its final check and refund.
            $locked->update(['status' => BookingStatus::Cancelled]);

            return $locked;
        }, 3);
    }

    /** Confirmed bookings only, oldest first, with the child's parent (name lives on users). */
    public function roster(TrialClass $class): Collection
    {
        return $class->bookings()
            ->with('student.guardian.user')
            ->where('status', BookingStatus::Confirmed)
            ->orderBy('confirmed_at')
            ->get();
    }

    /** Unlocked count, for display only. Seat decisions use confirmedCount() under the class lock. */
    public function seatsTaken(TrialClass $class): int
    {
        return $class->bookings()->where('status', BookingStatus::Confirmed)->count();
    }

    /**
     * Seats in use: confirmed bookings, plus (hold mode) holds that haven't expired. $exceptBookingId leaves out the
     * booking being decided. Locking read: always sees the latest committed rows, even under REPEATABLE READ.
     */
    private function seatsInUse(int $trialClassId, ?int $exceptBookingId = null): int
    {
        return Booking::where('trial_class_id', $trialClassId)
            ->where(fn ($q) => $q->where('status', BookingStatus::Confirmed)->when(
                Booking::holdsSeats(),
                fn ($q) => $q->orWhere(fn ($h) => $h->where('status', BookingStatus::PendingPayment)->where('created_at', '>=', Booking::holdCutoff())),
            ))
            ->when($exceptBookingId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->lockForUpdate()
            ->count();
    }

    /** Hold mode: unpaid holds past BOOKING_HOLD_MINUTES become EXPIRED. Runs under the class lock (no scheduler). */
    private function expireHolds(int $trialClassId, ?int $exceptBookingId = null): void
    {
        if (! Booking::holdsSeats()) {
            return;
        }

        $expired = Booking::where('trial_class_id', $trialClassId)
            ->where('status', BookingStatus::PendingPayment)
            ->where('created_at', '<', Booking::holdCutoff())
            ->when($exceptBookingId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->update(['status' => BookingStatus::Expired]);

        if ($expired > 0) {
            Log::info('booking.holds_expired', ['trial_class_id' => $trialClassId, 'count' => $expired]);
        }
    }

    private function studentAlreadyConfirmed(Booking $booking): bool
    {
        return Booking::where('student_id', $booking->student_id)
            ->where('trial_class_id', $booking->trial_class_id)
            ->where('status', BookingStatus::Confirmed)
            ->where('id', '!=', $booking->id)
            ->lockForUpdate()
            ->exists();
    }

    private function lockClass(int $trialClassId): TrialClass
    {
        return TrialClass::whereKey($trialClassId)->lockForUpdate()->firstOrFail();
    }

    /** The SUCCEEDED row stays; the REFUNDED row next to it completes the audit trail. */
    private function refund(Booking $booking, PaymentAttempt $attempt, string $reason): void
    {
        PaymentAttempt::create([
            'booking_id' => $attempt->booking_id,
            'status' => PaymentStatus::Refunded,
            'amount_cents' => $attempt->amount_cents,
            'idempotency_key' => "{$attempt->idempotency_key}:refund",
            'failure_reason' => $reason,
        ]);
        $this->gateway->refund($attempt->amount_cents);
        $this->log('booking.refunded', $booking, ['reason' => $reason]);
    }

    private function log(string $event, Booking $booking, array $context = []): void
    {
        Log::info($event, [
            'booking_id' => $booking->id,
            'trial_class_id' => $booking->trial_class_id,
            'student_id' => $booking->student_id,
        ] + $context);
    }
}
