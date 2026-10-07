<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\PaymentAttempt;
use App\Models\Student;
use App\Models\TrialClass;
use App\Payments\PaymentGateway;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/** BOOKING_MODE=hold: booking reserves the seat for BOOKING_HOLD_MINUTES; unpaid holds expire and free it. */
class SeatHoldTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        config(['booking.mode' => 'hold', 'booking.hold_minutes' => 1]);
    }

    public function test_booking_holds_the_last_seat_so_another_parent_cannot_book(): void
    {
        $this->freezeSecond();
        [$class, $a, $leo, $b, $ryan] = $this->lastSeatClass();

        $this->bookViaApi($a, $leo, $class)
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.hold_expires_at', now()->addMinute()->toIso8601String());

        $this->bookViaApi($b, $ryan, $class)->assertConflict()->assertJsonPath('errors.code.0', 'class_full');
        $this->actingAs($b)->getJson('/api/trial-classes')
            ->assertJsonPath('data.0.seats_taken', 3)
            ->assertJsonPath('data.0.seats_held', 1)
            ->assertJsonPath('data.0.seats_available', 0)
            ->assertJsonPath('data.0.is_full', true);
    }

    public function test_paying_within_the_hold_confirms_without_refund(): void
    {
        [$class, $a, $leo] = $this->lastSeatClass();
        $id = $this->bookViaApi($a, $leo, $class)->json('data.id');

        $this->travel(50)->seconds();
        $this->payViaApi($a, $id, 'a-1')->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertSame(1, $this->gateway->charges);
        $this->assertSame(0, $this->gateway->refunds);
        $this->assertSame(4, $this->confirmed($class));
    }

    public function test_expired_hold_releases_the_seat_and_late_payment_is_not_charged(): void
    {
        [$class, $a, $leo, $b, $ryan] = $this->lastSeatClass();
        $held = $this->bookViaApi($a, $leo, $class)->json('data.id');

        $this->travel(61)->seconds();
        $this->bookViaApi($b, $ryan, $class)->assertCreated();

        $this->payViaApi($a, $held, 'a-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('message', 'Your seat hold expired before payment, so the seat was released. You have not been charged.');
        $this->assertSame(0, $this->gateway->charges);
        $this->assertSame(0, PaymentAttempt::where('booking_id', $held)->count());
    }

    public function test_paying_after_the_hold_expired_is_rejected_even_if_the_seat_is_free(): void
    {
        [$class, $a, $leo] = $this->lastSeatClass();
        $id = $this->bookViaApi($a, $leo, $class)->json('data.id');

        $this->travel(61)->seconds();

        $this->payViaApi($a, $id, 'a-1')->assertJsonPath('data.status', 'EXPIRED');
        $this->assertSame(0, $this->gateway->charges);
    }

    public function test_hold_length_comes_from_config(): void
    {
        config(['booking.hold_minutes' => 5]);
        [$class, $a, $leo, $b, $ryan] = $this->lastSeatClass();
        $this->bookViaApi($a, $leo, $class);

        $this->travel(4)->minutes();
        $this->bookViaApi($b, $ryan, $class)->assertConflict();

        $this->travel(2)->minutes();
        $this->bookViaApi($b, $ryan, $class)->assertCreated();
    }

    public function test_booking_again_after_expiry_starts_a_new_hold(): void
    {
        [$class, $a, $leo] = $this->lastSeatClass();
        $first = $this->bookViaApi($a, $leo, $class)->json('data.id');

        $this->bookViaApi($a, $leo, $class)->assertOk()->assertJsonPath('data.id', $first);   // still held: same booking

        $this->travel(61)->seconds();
        $second = $this->bookViaApi($a, $leo, $class)->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(BookingStatus::Expired, Booking::find($first)->status);
    }

    public function test_hold_expiring_during_a_slow_charge_is_refunded_when_the_seat_was_taken(): void
    {
        [$class, $a, $leo, , $ryan] = $this->lastSeatClass();
        $id = $this->bookViaApi($a, $leo, $class)->json('data.id');

        // The vendor takes longer than the hold; meanwhile Ryan's parent books the released seat and pays.
        $this->gateway->beforeChargeReturns = function () use ($class, $ryan) {
            $this->travel(61)->seconds();
            $service = app(BookingService::class);
            $service->pay($service->createBooking($ryan->parent_id, $ryan->id, $class->id), 'b-1');
        };

        $this->payViaApi($a, $id, 'a-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED_CLASS_FULL')
            ->assertJsonPath('message', 'Sorry, the last seat in this class was just taken. Your payment has been refunded.');
        $this->assertSame(1, $this->gateway->refunds);
        $this->assertSame(4, $this->confirmed($class));
    }

    public function test_hold_expiring_during_a_slow_charge_still_confirms_if_the_seat_is_free(): void
    {
        [$class, $a, $leo] = $this->lastSeatClass();
        $id = $this->bookViaApi($a, $leo, $class)->json('data.id');
        $this->gateway->beforeChargeReturns = fn () => $this->travel(61)->seconds();

        $this->payViaApi($a, $id, 'a-1')->assertJsonPath('data.status', 'CONFIRMED');
        $this->assertSame(0, $this->gateway->refunds);
    }

    public function test_declined_payment_frees_the_held_seat_at_once(): void
    {
        [$class, $a, $leo, $b, $ryan] = $this->lastSeatClass();
        $id = $this->bookViaApi($a, $leo, $class)->json('data.id');

        $this->payViaApi($a, $id, 'a-1', 'decline')->assertJsonPath('data.status', 'PAYMENT_FAILED');

        $this->bookViaApi($b, $ryan, $class)->assertCreated();
    }

    public function test_cancelling_frees_the_held_seat(): void
    {
        [$class, $a, $leo, $b, $ryan] = $this->lastSeatClass();
        $id = $this->bookViaApi($a, $leo, $class)->json('data.id');

        $this->actingAs($a)->postJson("/api/bookings/{$id}/cancel")->assertJsonPath('data.status', 'CANCELLED');

        $this->bookViaApi($b, $ryan, $class)->assertCreated();
    }

    public function test_first_to_pay_mode_has_no_hold(): void
    {
        config(['booking.mode' => 'first_to_pay']);
        [$class, $a, $leo, $b, $ryan] = $this->lastSeatClass();

        $this->bookViaApi($a, $leo, $class)->assertCreated()->assertJsonPath('data.hold_expires_at', null);
        $this->bookViaApi($b, $ryan, $class)->assertCreated();   // a pending booking holds nothing
    }

    /** @return array{TrialClass, \App\Models\User, Student, \App\Models\User, Student} class with 3 of 4 seats confirmed */
    private function lastSeatClass(): array
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 3);
        $a = $this->parentUser('Parent A');
        $b = $this->parentUser('Parent B');

        return [$class, $a, $this->student($a, 'Leo'), $b, $this->student($b, 'Ryan')];
    }

    private function confirmed(TrialClass $class): int
    {
        return $class->bookings()->where('status', BookingStatus::Confirmed)->count();
    }
}
