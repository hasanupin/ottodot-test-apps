<?php

namespace Tests\Feature\Concerns;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Student;
use App\Models\TrialClass;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** Small builders for feature tests (synthetic data only). */
trait BuildsSchoolData
{
    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function teacherUser(string $name = 'Test Teacher'): User
    {
        return User::factory()->teacher()->create(['name' => $name]);
    }

    protected function parentUser(string $name = 'Test Parent'): User
    {
        return User::factory()->parent()->create(['name' => $name]);
    }

    protected function student(User $parent, string $name = 'Test Kid', ?int $grade = 3): Student
    {
        return Student::create(['parent_id' => $parent->parent_id, 'name' => $name, 'grade' => $grade]);
    }

    protected function trialClass(User $teacher, array $overrides = []): TrialClass
    {
        return TrialClass::create($overrides + [
            'teacher_id' => $teacher->teacher_id,
            'subject' => 'math',
            'title' => 'Test Class',
            'starts_at' => now()->addDays(2),
            'capacity' => 4,
            'price_cents' => 1500,
        ]);
    }

    protected function booking(Student $student, TrialClass $class, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $booking = Booking::create([
            'student_id' => $student->id,
            'trial_class_id' => $class->id,
            'status' => $status,
            'confirmed_at' => $status === BookingStatus::Confirmed ? now() : null,
        ]);

        $booking->paymentAttempts()->create([
            'status' => $status === BookingStatus::Confirmed ? PaymentStatus::Succeeded : PaymentStatus::Failed,
            'amount_cents' => $class->price_cents,
            'idempotency_key' => "test-booking-{$booking->id}",
            'failure_reason' => $status === BookingStatus::Confirmed ? null : 'card_declined',
        ]);

        return $booking;
    }

    /** $count confirmed bookings (with a SUCCEEDED payment each) for fresh students of a fresh parent. */
    protected function fillSeats(TrialClass $class, int $count): void
    {
        $parent = $this->parentUser('Seat Filler');

        for ($i = 1; $i <= $count; $i++) {
            $this->booking($this->student($parent, "Filler {$i}"), $class);
        }
    }

    protected function bookViaApi(User $parent, Student $student, TrialClass $class): TestResponse
    {
        return $this->actingAs($parent)->postJson('/api/bookings', ['student_id' => $student->id, 'trial_class_id' => $class->id]);
    }

    protected function payViaApi(User $parent, int $bookingId, string $key, string $simulate = 'success'): TestResponse
    {
        return $this->actingAs($parent)->postJson("/api/bookings/{$bookingId}/pay", ['idempotency_key' => $key, 'simulate' => $simulate]);
    }

    /** Names on the class roster, read as an admin. */
    protected function rosterNames(TrialClass $class): array
    {
        return $this->actingAs($this->admin())->getJson("/api/trial-classes/{$class->id}/roster")
            ->assertOk()
            ->json('data.students.*.student_name');
    }
}
