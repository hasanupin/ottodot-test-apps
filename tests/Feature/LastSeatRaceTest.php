<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\PaymentAttempt;
use App\Models\TrialClass;
use App\Payments\PaymentGateway;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * PHPUnit runs in one process, so these tests reproduce the race deterministically (ordering and the gateway hook).
 * Truly parallel payments are exercised by `race:simulate` (step 11).
 */
class LastSeatRaceTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_brief_scenario_user_a_is_rejected_without_being_charged(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 3);
        $parentA = $this->parentUser('Parent A');
        $parentB = $this->parentUser('Parent B');
        $a = $this->bookViaApi($parentA, $this->student($parentA, 'Kid A'), $class)->json('data.id');
        $b = $this->bookViaApi($parentB, $this->student($parentB, 'Kid B'), $class)->json('data.id');

        $this->payViaApi($parentB, $b, 'b-1')->assertJsonPath('data.status', 'CONFIRMED');
        $this->payViaApi($parentA, $a, 'a-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED_CLASS_FULL')
            ->assertJsonPath('message', 'Sorry, the last seat in this class was just taken. You have not been charged.');

        $this->assertSame(0, PaymentAttempt::where('booking_id', $a)->count());
        $this->assertSame(1, $this->gateway->charges);
        $roster = $this->rosterNames($class);
        $this->assertContains('Kid B', $roster);
        $this->assertNotContains('Kid A', $roster);
        $this->assertExactlyFourConfirmed($class);
    }

    public function test_simultaneous_payments_loser_is_refunded(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 3);
        $parentA = $this->parentUser('Parent A');
        $parentB = $this->parentUser('Parent B');
        $a = $this->bookViaApi($parentA, $this->student($parentA, 'Kid A'), $class)->json('data.id');
        $b = $this->bookViaApi($parentB, $this->student($parentB, 'Kid B'), $class)->json('data.id');

        // A passed the pre-check and is being charged; B pays in full meanwhile and takes the last seat.
        $this->gateway->beforeChargeReturns = fn () => app(BookingService::class)->pay(Booking::find($b), 'b-1');

        $this->payViaApi($parentA, $a, 'a-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED_CLASS_FULL')
            ->assertJsonPath('message', 'Sorry, the last seat in this class was just taken. Your payment has been refunded.');

        $this->assertSame(BookingStatus::Confirmed, Booking::find($b)->status);
        $attempts = PaymentAttempt::where('booking_id', $a)->orderBy('id')->get();
        $this->assertSame([PaymentStatus::Succeeded, PaymentStatus::Refunded], $attempts->pluck('status')->all());
        $this->assertSame('class_full', $attempts[1]->failure_reason);
        $this->assertSame('a-1:refund', $attempts[1]->idempotency_key);
        $this->assertSame(1, $this->gateway->refunds);
        $this->assertExactlyFourConfirmed($class);
    }

    public function test_two_payments_for_the_same_booking_confirm_once_and_refund_the_other(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 3);
        $parent = $this->parentUser();
        $id = $this->bookViaApi($parent, $this->student($parent), $class)->json('data.id');

        // A second tab pays the same booking with another key while the first charge is in flight.
        $this->gateway->beforeChargeReturns = fn () => app(BookingService::class)->pay(Booking::find($id), 'k2');

        $this->payViaApi($parent, $id, 'k1')->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        // Both charges went through; the late one (k1) is refunded, so the parent pays once.
        $attempts = PaymentAttempt::where('booking_id', $id)->get()->keyBy('idempotency_key');
        $this->assertSame(PaymentStatus::Succeeded, $attempts['k2']->status);
        $this->assertSame(PaymentStatus::Succeeded, $attempts['k1']->status);
        $this->assertSame(PaymentStatus::Refunded, $attempts['k1:refund']->status);
        $this->assertSame('booking_closed', $attempts['k1:refund']->failure_reason);
        $this->assertSame(2, $this->gateway->charges);
        $this->assertSame(1, $this->gateway->refunds);
        $this->assertExactlyFourConfirmed($class);
    }

    public function test_roster_never_exceeds_capacity(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $parent = $this->parentUser();
        $ids = collect(range(1, 6))
            ->map(fn ($i) => $this->bookViaApi($parent, $this->student($parent, "Kid {$i}"), $class)->json('data.id'));

        $statuses = $ids->map(fn ($id) => $this->payViaApi($parent, $id, "key-{$id}")->json('data.status'));

        $this->assertSame(['CONFIRMED', 'CONFIRMED', 'CONFIRMED', 'CONFIRMED', 'FAILED_CLASS_FULL', 'FAILED_CLASS_FULL'], $statuses->all());
        $this->assertCount(4, $this->rosterNames($class));
        $this->assertSame(4, $this->gateway->charges);   // the last two were rejected before charging
        $this->assertExactlyFourConfirmed($class);
    }

    public function test_confirmed_roster_excludes_every_non_confirmed_status(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 4);
        $parent = $this->parentUser();
        foreach ([BookingStatus::PendingPayment, BookingStatus::PaymentFailed, BookingStatus::FailedClassFull, BookingStatus::Cancelled] as $status) {
            $this->booking($this->student($parent, $status->value), $class, $status);
        }

        $roster = $this->actingAs($this->admin())->getJson("/api/trial-classes/{$class->id}/roster")
            ->assertOk()
            ->assertJsonPath('data.capacity', 4)
            ->assertJsonPath('data.confirmed_count', 4)
            ->json('data.students.*.student_name');

        $this->assertEqualsCanonicalizing(['Filler 1', 'Filler 2', 'Filler 3', 'Filler 4'], $roster);
        $this->assertExactlyFourConfirmed($class);
    }

    private function assertExactlyFourConfirmed(TrialClass $class): void
    {
        $this->assertSame(4, $class->bookings()->where('status', BookingStatus::Confirmed)->count());
    }
}
