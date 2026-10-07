<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_parent_can_book_a_trial_class_for_their_child(): void
    {
        $parent = $this->parentUser();
        $kid = $this->student($parent, 'Leo');
        $class = $this->trialClass($this->teacherUser());

        $this->bookViaApi($parent, $kid, $class)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.student.name', 'Leo');

        // A pending booking does not take a seat.
        $this->actingAs($parent)->getJson('/api/trial-classes')
            ->assertJsonPath('data.0.seats_taken', 0)
            ->assertJsonPath('data.0.seats_available', 4);
    }

    public function test_existing_pending_booking_is_returned_instead_of_a_new_one(): void
    {
        $parent = $this->parentUser();
        $kid = $this->student($parent);
        $class = $this->trialClass($this->teacherUser());

        $id = $this->bookViaApi($parent, $kid, $class)->assertCreated()->json('data.id');

        $this->bookViaApi($parent, $kid, $class)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Booking::count());
    }

    public function test_child_already_confirmed_in_class_cannot_book_again(): void
    {
        $parent = $this->parentUser();
        $kid = $this->student($parent);
        $class = $this->trialClass($this->teacherUser());
        $this->booking($kid, $class, BookingStatus::Confirmed);

        $this->bookViaApi($parent, $kid, $class)
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code.0', 'already_booked');
    }

    public function test_full_class_cannot_be_booked(): void
    {
        $parent = $this->parentUser();
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 4);

        $this->bookViaApi($parent, $this->student($parent), $class)
            ->assertConflict()
            ->assertJsonPath('errors.code.0', 'class_full');
    }

    public function test_parent_cannot_book_someone_elses_child(): void
    {
        $someoneElsesKid = $this->student($this->parentUser('Other Parent'));
        $class = $this->trialClass($this->teacherUser());

        $this->bookViaApi($this->parentUser(), $someoneElsesKid, $class)
            ->assertForbidden()
            ->assertJsonPath('errors.code.0', 'student_not_owned');
        $this->assertSame(0, Booking::count());
    }

    public function test_class_that_already_started_cannot_be_booked(): void
    {
        $parent = $this->parentUser();
        $class = $this->trialClass($this->teacherUser(), ['starts_at' => now()->subHour()]);

        $this->bookViaApi($parent, $this->student($parent), $class)
            ->assertConflict()
            ->assertJsonPath('errors.code.0', 'class_started');
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->actingAs($this->parentUser())->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['student_id', 'trial_class_id']);
    }
}
