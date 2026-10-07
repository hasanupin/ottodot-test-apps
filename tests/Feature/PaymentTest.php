<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\PaymentAttempt;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_successful_payment_confirms_booking_and_adds_child_to_roster(): void
    {
        $parent = $this->parentUser();
        $class = $this->trialClass($this->teacherUser(), ['title' => 'Fractions']);
        $id = $this->bookViaApi($parent, $this->student($parent, 'Leo'), $class)->json('data.id');

        $this->payViaApi($parent, $id, 'pay-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('message', 'Booked! Leo is confirmed for Fractions.');

        $this->assertSame([PaymentStatus::Succeeded], PaymentAttempt::pluck('status')->all());
        $this->assertSame(['Leo'], $this->rosterNames($class));
    }

    public function test_declined_payment_does_not_add_child_to_roster(): void
    {
        $parent = $this->parentUser();
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 1);
        $id = $this->bookViaApi($parent, $this->student($parent, 'Mia'), $class)->json('data.id');

        $this->payViaApi($parent, $id, 'pay-1', 'decline')
            ->assertOk()
            ->assertJsonPath('data.status', 'PAYMENT_FAILED');

        $attempt = PaymentAttempt::where('booking_id', $id)->sole();
        $this->assertSame(PaymentStatus::Failed, $attempt->status);
        $this->assertSame('card_declined', $attempt->failure_reason);
        $this->assertSame(['Filler 1'], $this->rosterNames($class));
        $this->actingAs($parent)->getJson('/api/trial-classes')->assertJsonPath('data.0.seats_available', 3);
    }

    public function test_same_idempotency_key_never_charges_twice(): void
    {
        $parent = $this->parentUser();
        $id = $this->bookViaApi($parent, $this->student($parent), $this->trialClass($this->teacherUser()))->json('data.id');

        $this->payViaApi($parent, $id, 'same-key')->assertJsonPath('data.status', 'CONFIRMED');
        $this->payViaApi($parent, $id, 'same-key')->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertSame(1, $this->gateway->charges);
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_idempotency_key_cannot_be_reused_for_another_booking(): void
    {
        $parent = $this->parentUser();
        $class = $this->trialClass($this->teacherUser());
        $first = $this->bookViaApi($parent, $this->student($parent, 'Leo'), $class)->json('data.id');
        $second = $this->bookViaApi($parent, $this->student($parent, 'Mia'), $class)->json('data.id');
        $this->payViaApi($parent, $first, 'key-1');

        $this->payViaApi($parent, $second, 'key-1')
            ->assertConflict()
            ->assertJsonPath('errors.code.0', 'idempotency_key_reused');
        $this->assertSame(BookingStatus::PendingPayment, Booking::find($second)->status);
        $this->assertSame(1, $this->gateway->charges);
    }

    public function test_paying_a_finished_booking_does_not_charge(): void
    {
        $parent = $this->parentUser();
        $id = $this->bookViaApi($parent, $this->student($parent), $this->trialClass($this->teacherUser()))->json('data.id');
        $this->payViaApi($parent, $id, 'key-1');

        $this->payViaApi($parent, $id, 'key-2')->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertSame(1, $this->gateway->charges);
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_pending_booking_can_be_cancelled_but_confirmed_cannot(): void
    {
        $parent = $this->parentUser();
        $class = $this->trialClass($this->teacherUser());
        $pending = $this->bookViaApi($parent, $this->student($parent, 'Leo'), $class)->json('data.id');
        $confirmed = $this->bookViaApi($parent, $this->student($parent, 'Mia'), $class)->json('data.id');
        $this->payViaApi($parent, $confirmed, 'key-1');

        $this->actingAs($parent)->postJson("/api/bookings/{$pending}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('message', 'Booking cancelled.');
        $this->actingAs($parent)->postJson("/api/bookings/{$confirmed}/cancel")
            ->assertConflict()
            ->assertJsonPath('errors.code.0', 'not_cancellable');
        $this->assertSame(BookingStatus::Confirmed, Booking::find($confirmed)->status);
    }

    public function test_invalid_payment_input_is_rejected(): void
    {
        $parent = $this->parentUser();
        $id = $this->bookViaApi($parent, $this->student($parent), $this->trialClass($this->teacherUser()))->json('data.id');

        $this->actingAs($parent)->postJson("/api/bookings/{$id}/pay", ['simulate' => 'maybe', 'delay_ms' => 9000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key', 'simulate', 'delay_ms']);
        $this->assertSame(0, $this->gateway->charges);
    }
}
