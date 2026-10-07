<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Student;
use App\Models\TrialClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TrialClassService
{
    /**
     * Admin and parent: every class. Teacher: own classes only. Each class carries seats_taken (confirmed bookings).
     * $studentId (parent's own child only, else 404) adds that child's booking status per class.
     */
    public function list(User $actor, ?int $studentId = null): Collection
    {
        // ponytail: no pagination (demo-sized data); add ->paginate() when the class list grows.
        $classes = TrialClass::with('teacher.user')
            ->withCount($this->seatCounts())
            ->when($actor->role === UserRole::Teacher, fn ($q) => $q->where('teacher_id', $actor->teacher_id))
            ->orderBy('starts_at')
            ->get();

        if ($studentId !== null) {
            // 404 rather than 403: don't reveal which student ids exist.
            abort_unless($actor->role === UserRole::Parent
                && Student::whereKey($studentId)->where('parent_id', $actor->parent_id)->exists(), 404);

            $statuses = Booking::where('student_id', $studentId)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::PendingPayment])
                ->pluck('status', 'trial_class_id');
            $classes->each(fn ($c) => $c->student_booking_status = $statuses->get($c->id)?->value);
        }

        return $classes;
    }

    public function create(array $data): TrialClass
    {
        return TrialClass::create($data)->load('teacher.user')->loadCount($this->seatCounts());
    }

    public function update(TrialClass $class, array $data): TrialClass
    {
        return DB::transaction(function () use ($class, $data) {
            // Same row lock the booking flow takes, so a payment can't confirm a seat between the count and the update.
            $class = TrialClass::lockForUpdate()->findOrFail($class->id);
            $confirmed = $class->bookings()->where('status', BookingStatus::Confirmed)->count();

            if ($data['capacity'] < $confirmed) {
                throw new BusinessRuleException("Capacity can't be lower than the {$confirmed} confirmed bookings.");
            }

            $class->update($data);

            return $class->load('teacher.user')->loadCount($this->seatCounts());
        });
    }

    public function delete(TrialClass $class): void
    {
        if ($class->bookings()->exists()) {
            throw new BusinessRuleException('This class has bookings and cannot be deleted.');
        }

        $class->delete();
    }

    /**
     * withCount spec (display only; seat decisions lock in BookingService): seats_taken = confirmed,
     * seats_held = unexpired holds (hold mode; always 0 in first_to_pay).
     */
    private function seatCounts(): array
    {
        return [
            'bookings as seats_taken' => fn ($q) => $q->where('status', BookingStatus::Confirmed),
            'bookings as seats_held' => fn ($q) => $q->where('status', BookingStatus::PendingPayment)
                ->where('created_at', '>=', Booking::holdCutoff())
                ->when(! Booking::holdsSeats(), fn ($q) => $q->whereRaw('1 = 0')),
        ];
    }
}
