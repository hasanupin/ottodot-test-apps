<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TrialClass;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Deterministic, synthetic demo data (invented names, @example.com) covering every case the brief requires:
 * a class with free seats, a class with exactly 3 confirmed (last-seat race), a duplicate attempt (Leo in class 2),
 * a payment failure (Mia in class 5) and a full class. Seeded directly: these rows are history that already happened.
 * Every account's password is "password".
 */
class DemoSeeder extends Seeder
{
    private const PRICE_CENTS = 1500;

    private string $password;

    /** @var array<string, Student> */
    private array $students = [];

    /** @var array<string, Teacher> */
    private array $teachers = [];

    public function run(): void
    {
        // Hash once: the `hashed` cast leaves an already-hashed value alone, so 15 users don't cost 15 bcrypt runs.
        $this->password = Hash::make('password');

        DB::transaction(function () {
            $this->seedParents();
            $this->seedStaff();
            $this->seedClassesAndBookings();
        });
    }

    private function seedParents(): void
    {
        $parents = [
            ['Maya Tan', 'maya.tan@example.com', ['Leo' => 3, 'Mia' => 5]],
            ['Daniel Okafor', 'daniel.okafor@example.com', ['Ava' => 4]],
            ['Sofia Rahman', 'sofia.rahman@example.com', ['Noah' => 2]],
            ['Ethan Wong', 'ethan.wong@example.com', ['Zara' => 4]],
            ['Priya Nair', 'priya.nair@example.com', ['Ryan' => 5]],
            ['Lucas Silva', 'lucas.silva@example.com', ['Emma' => 3]],
            ['Hana Sato', 'hana.sato@example.com', ['Kai' => 4]],
            ['Omar Haddad', 'omar.haddad@example.com', ['Lina' => 3]],
            ['Grace Lim', 'grace.lim@example.com', ['Ben' => 5]],
            ['Felix Braun', 'felix.braun@example.com', ['Iris' => 2]],
        ];

        foreach ($parents as [$name, $email, $children]) {
            $guardian = Guardian::create();
            $this->user($name, $email, UserRole::Parent, ['parent_id' => $guardian->id]);

            foreach ($children as $child => $grade) {
                $this->students[$child] = $guardian->students()->create(['name' => $child, 'grade' => $grade]);
            }
        }
    }

    private function seedStaff(): void
    {
        foreach ([
            'Ms. Rivera' => 'rivera@example.com',
            'Mr. Chen' => 'chen@example.com',
            'Ms. Okoye' => 'okoye@example.com',
            'Mr. Patel' => 'patel@example.com',
        ] as $name => $email) {
            $teacher = Teacher::create();
            $this->user($name, $email, UserRole::Teacher, ['teacher_id' => $teacher->id]);
            $this->teachers[$name] = $teacher;
        }

        $this->user('Jordan Admin', 'admin@example.com', UserRole::Admin);
    }

    private function seedClassesAndBookings(): void
    {
        // Created in this order so IDs are predictable on a fresh DB (class 1 = race class). Docs/demos only.
        $classes = [
            ['Math Explorers: Fractions', 'math', 'Ms. Rivera', 2, 16, ['Ava', 'Noah', 'Zara'], []],
            ['Science Lab: Volcanoes', 'science', 'Mr. Chen', 3, 15, ['Leo'], []],
            ['Space & Planets', 'science', 'Ms. Okoye', 4, 10, [], []],
            ['Coding Logic Puzzles', 'math', 'Mr. Patel', 5, 14, ['Emma', 'Kai', 'Lina', 'Ben'], []],
            ['Chemistry of Colours', 'science', 'Ms. Rivera', 6, 11, [], ['Mia']],
        ];

        foreach ($classes as [$title, $subject, $teacher, $inDays, $hour, $confirmed, $paymentFailed]) {
            $class = TrialClass::create([
                'teacher_id' => $this->teachers[$teacher]->id,
                'subject' => $subject,
                'title' => $title,
                'starts_at' => now()->addDays($inDays)->setTime($hour, 0),
                'capacity' => 4,
                'price_cents' => self::PRICE_CENTS,
            ]);

            foreach ($confirmed as $child) {
                $this->booking($class, $child, BookingStatus::Confirmed, PaymentStatus::Succeeded);
            }
            foreach ($paymentFailed as $child) {
                $this->booking($class, $child, BookingStatus::PaymentFailed, PaymentStatus::Failed, 'card_declined');
            }
        }
    }

    private function booking(TrialClass $class, string $child, BookingStatus $status, PaymentStatus $payment, ?string $failureReason = null): void
    {
        $booking = Booking::create([
            'student_id' => $this->students[$child]->id,
            'trial_class_id' => $class->id,
            'status' => $status,
            'confirmed_at' => $status === BookingStatus::Confirmed ? now() : null,
        ]);

        $booking->paymentAttempts()->create([
            'status' => $payment,
            'amount_cents' => self::PRICE_CENTS,
            'idempotency_key' => "seed-booking-{$booking->id}",
            'failure_reason' => $failureReason,
        ]);
    }

    private function user(string $name, string $email, UserRole $role, array $links = []): void
    {
        User::create(['name' => $name, 'email' => $email, 'password' => $this->password, 'role' => $role] + $links);
    }
}
