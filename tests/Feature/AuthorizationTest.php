<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_guest_cannot_use_the_booking_api(): void
    {
        $this->postJson('/api/bookings', [])->assertUnauthorized()->assertJsonPath('success', false);
        $this->getJson('/api/trial-classes')->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_teacher_and_admin_cannot_book(): void
    {
        $teacher = $this->teacherUser();
        $class = $this->trialClass($teacher);
        $body = ['student_id' => $this->student($this->parentUser())->id, 'trial_class_id' => $class->id];

        $this->actingAs($teacher)->postJson('/api/bookings', $body)->assertForbidden();
        $this->actingAs($this->admin())->postJson('/api/bookings', $body)->assertForbidden();
        $this->assertSame(0, Booking::count());
    }

    public function test_parent_cannot_see_or_pay_another_parents_booking(): void
    {
        $parentA = $this->parentUser('Parent A');
        $id = $this->bookViaApi($parentA, $this->student($parentA), $this->trialClass($this->teacherUser()))->json('data.id');
        $parentB = $this->parentUser('Parent B');

        $this->actingAs($parentB)->getJson("/api/bookings/{$id}")->assertNotFound();
        $this->payViaApi($parentB, $id, 'b-key')->assertNotFound();
        $this->actingAs($parentB)->postJson("/api/bookings/{$id}/cancel")->assertNotFound();

        $this->assertSame(0, $this->gateway->charges);
        $this->actingAs($parentA)->getJson("/api/bookings/{$id}")->assertOk()->assertJsonPath('data.status', 'PENDING_PAYMENT');
    }

    public function test_parent_cannot_read_a_roster(): void
    {
        $class = $this->trialClass($this->teacherUser());

        $this->actingAs($this->parentUser())->getJson("/api/trial-classes/{$class->id}/roster")->assertForbidden();
    }

    public function test_teacher_cannot_read_another_teachers_roster(): void
    {
        $x = $this->teacherUser('Teacher X');
        $y = $this->teacherUser('Teacher Y');

        $this->actingAs($x)->getJson("/api/trial-classes/{$this->trialClass($y)->id}/roster")->assertForbidden();
        $this->actingAs($x)->getJson("/api/trial-classes/{$this->trialClass($x)->id}/roster")
            ->assertOk()
            ->assertJsonPath('data.trial_class.teacher', 'Teacher X');
    }

    public function test_admin_can_read_any_roster(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 2);

        $this->actingAs($this->admin())->getJson("/api/trial-classes/{$class->id}/roster")
            ->assertOk()
            ->assertJsonPath('data.confirmed_count', 2)
            ->assertJsonPath('data.students.0.parent_name', 'Seat Filler');
    }

    public function test_class_list_shows_booking_status_only_for_own_child(): void
    {
        $parent = $this->parentUser();
        $kid = $this->student($parent);
        $teacher = $this->teacherUser();
        $class = $this->trialClass($teacher);
        $this->trialClass($teacher, ['title' => 'Not booked', 'starts_at' => now()->addDays(5)]);
        $this->bookViaApi($parent, $kid, $class);
        $otherKid = $this->student($this->parentUser('Other Parent'));

        $this->actingAs($parent)->getJson("/api/trial-classes?student_id={$kid->id}")
            ->assertOk()
            ->assertJsonPath('data.0.student_booking_status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.1.student_booking_status', null);
        $this->actingAs($parent)->getJson('/api/trial-classes')->assertJsonMissingPath('data.0.student_booking_status');
        $this->actingAs($parent)->getJson("/api/trial-classes?student_id={$otherKid->id}")->assertNotFound();
        $this->actingAs($this->admin())->getJson("/api/trial-classes?student_id={$kid->id}")->assertNotFound();
    }
}
