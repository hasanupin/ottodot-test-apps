<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Guardian;
use App\Models\PaymentAttempt;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TrialClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_account_per_role_logs_in(): void
    {
        $this->seed();

        foreach (['maya.tan@example.com' => 'parent', 'rivera@example.com' => 'teacher', 'admin@example.com' => 'admin'] as $email => $role) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])
                ->assertOk()
                ->assertJsonPath('data.user.role', $role);

            $this->postJson('/api/logout')->assertOk();
        }
    }

    public function test_seed_covers_every_case_the_brief_requires(): void
    {
        $this->seed();

        // Confirmed seats per class, in seeding order: race class (3 of 4), available, happy path, full, payment failure.
        $confirmed = TrialClass::orderBy('id')->get()
            ->map(fn (TrialClass $c) => $c->bookings()->where('status', BookingStatus::Confirmed)->count())
            ->all();
        $this->assertSame([3, 1, 0, 4, 0], $confirmed);

        $failed = Booking::where('status', BookingStatus::PaymentFailed)->with('student')->get();
        $this->assertCount(1, $failed);
        $this->assertSame('Mia', $failed->first()->student->name);
        $this->assertSame(0, Booking::where('status', BookingStatus::PendingPayment)->count());

        $this->assertSame(10, Guardian::count());
        $this->assertSame(4, Teacher::count());
        $this->assertSame(15, User::count());
        $this->assertSame(11, Student::count());
        $this->assertSame(9, PaymentAttempt::count());
    }
}
