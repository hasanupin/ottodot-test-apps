<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\TestCase;

class BookingListTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    public function test_admin_sees_all_bookings_and_can_filter(): void
    {
        $rivera = $this->teacherUser();
        $math = $this->trialClass($rivera, ['title' => 'Math']);
        $science = $this->trialClass($rivera, ['title' => 'Science']);
        $maya = $this->parentUser();
        $leo = $this->student($maya, 'Leo');
        $ava = $this->student($this->parentUser(), 'Ava');
        $this->booking($leo, $math);
        $this->booking($leo, $science, BookingStatus::PaymentFailed);
        $this->booking($ava, $math);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'status', 'confirmed_at', 'created_at', 'student' => ['id', 'name'],
                'parent' => ['id', 'name'], 'trial_class' => ['id', 'title', 'starts_at'], 'payment_attempts']]]);

        $this->actingAs($admin)->getJson('/api/bookings?status=PAYMENT_FAILED')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trial_class.title', 'Science')
            ->assertJsonPath('data.0.payment_attempts.0.failure_reason', 'card_declined');
        $this->actingAs($admin)->getJson("/api/bookings?trial_class_id={$math->id}")->assertJsonCount(2, 'data');
        $this->actingAs($admin)->getJson("/api/bookings?student_id={$ava->id}")->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson("/api/bookings?parent_id={$maya->parent_id}")->assertJsonCount(2, 'data');
    }

    public function test_invalid_filter_is_rejected(): void
    {
        $this->actingAs($this->admin())->getJson('/api/bookings?status=confirmed')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_teacher_sees_only_bookings_in_own_classes_even_when_filtering(): void
    {
        $rivera = $this->teacherUser();
        $own = $this->trialClass($rivera);
        $other = $this->trialClass($this->teacherUser());
        $kid = $this->student($this->parentUser());
        $mine = $this->booking($kid, $own);
        $this->booking($kid, $other);

        $this->actingAs($rivera)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        // A filter can only narrow the role scope, never widen it.
        $this->actingAs($rivera)->getJson("/api/bookings?trial_class_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_parent_sees_only_own_childrens_booking_history(): void
    {
        $maya = $this->parentUser();
        $class = $this->trialClass($this->teacherUser());
        $leo = $this->student($maya, 'Leo');
        $this->booking($leo, $class);
        $this->booking($this->student($maya, 'Mia'), $class, BookingStatus::PaymentFailed);
        $stranger = $this->student($this->parentUser(), 'Stranger');
        $this->booking($stranger, $class);

        $this->actingAs($maya)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($maya)->getJson("/api/bookings?student_id={$stranger->id}")
            ->assertJsonCount(0, 'data');
    }
}
