<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\TestCase;

class PaymentListTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    public function test_admin_lists_payments_and_filters_by_status(): void
    {
        $class = $this->trialClass($this->teacherUser(), ['title' => 'Fractions']);
        $parent = $this->parentUser();
        $paid = $this->booking($this->student($parent, 'Leo'), $class);
        $this->booking($this->student($parent, 'Mia'), $class, BookingStatus::PaymentFailed);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'status', 'amount_cents', 'failure_reason', 'created_at',
                'booking' => ['id', 'status', 'student' => ['id', 'name'], 'trial_class' => ['id', 'title']]]]])
            ->assertJsonMissingPath('data.0.idempotency_key');

        $this->actingAs($admin)->getJson('/api/payments?status=FAILED')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.failure_reason', 'card_declined')
            ->assertJsonPath('data.0.booking.student.name', 'Mia');

        $this->actingAs($admin)->getJson("/api/payments?booking_id={$paid->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'SUCCEEDED');
    }

    public function test_teacher_and_parent_cannot_list_payments(): void
    {
        $this->actingAs($this->teacherUser())->getJson('/api/payments')->assertForbidden();
        $this->actingAs($this->parentUser())->getJson('/api/payments')->assertForbidden();
    }
}
