<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Guardian;
use App\Models\PaymentAttempt;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TrialClass;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Database-level guarantees only: these hold even if application code has a bug. */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_confirmed_booking_for_same_student_and_class_is_rejected(): void
    {
        [$student, $class] = $this->makeStudentAndClass();
        $this->book($student, $class, BookingStatus::Confirmed);

        $this->expectException(QueryException::class);
        $this->book($student, $class, BookingStatus::Confirmed);
    }

    public function test_non_confirmed_bookings_can_repeat_for_same_student_and_class(): void
    {
        [$student, $class] = $this->makeStudentAndClass();

        foreach ([
            BookingStatus::PaymentFailed,
            BookingStatus::Cancelled,
            BookingStatus::FailedClassFull,
            BookingStatus::PendingPayment,
            BookingStatus::Confirmed,
        ] as $status) {
            $this->book($student, $class, $status);
        }

        $this->assertSame(5, Booking::count());
        $this->assertSame(1, Booking::where('status', BookingStatus::Confirmed)->count());
    }

    public function test_same_class_can_have_confirmed_bookings_for_different_students(): void
    {
        [$student, $class] = $this->makeStudentAndClass();
        $other = Student::create(['parent_id' => $student->parent_id, 'name' => 'Other Kid', 'grade' => 4]);

        $this->book($student, $class, BookingStatus::Confirmed);
        $this->book($other, $class, BookingStatus::Confirmed);

        $this->assertSame(2, Booking::where('status', BookingStatus::Confirmed)->count());
    }

    public function test_invalid_booking_status_is_rejected(): void
    {
        [$student, $class] = $this->makeStudentAndClass();

        $this->expectException(QueryException::class);
        $this->rawBooking($student, $class, 'bogus');
    }

    public function test_capacity_above_four_is_rejected(): void
    {
        $teacher = Teacher::create();

        $this->expectException(QueryException::class);
        $this->makeClass($teacher, ['capacity' => 5]);
    }

    public function test_duplicate_payment_idempotency_key_is_rejected(): void
    {
        [$student, $class] = $this->makeStudentAndClass();
        $booking = $this->book($student, $class, BookingStatus::PendingPayment);
        $this->pay($booking, 'same-key');

        $this->expectException(QueryException::class);
        $this->pay($booking, 'same-key');
    }

    public function test_invalid_payment_status_is_rejected(): void
    {
        [$student, $class] = $this->makeStudentAndClass();
        $booking = $this->book($student, $class, BookingStatus::PendingPayment);

        $this->expectException(QueryException::class);
        DB::table('payment_attempts')->insert([
            'booking_id' => $booking->id,
            'status' => 'processing',
            'amount_cents' => 1500,
            'idempotency_key' => 'key-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_expired_is_a_valid_booking_status(): void
    {
        [$student, $class] = $this->makeStudentAndClass();

        $this->rawBooking($student, $class, 'EXPIRED');   // hold mode: an unpaid hold that ran out

        $this->assertSame(BookingStatus::Expired, Booking::sole()->status);
    }

    public function test_lowercase_status_is_rejected(): void
    {
        [$student, $class] = $this->makeStudentAndClass();

        // utf8mb4_bin makes the CHECK case-sensitive; MySQL's default collation would accept this.
        $this->expectException(QueryException::class);
        $this->rawBooking($student, $class, 'confirmed');
    }

    public function test_confirmed_flag_is_generated_from_status(): void
    {
        [$student, $class] = $this->makeStudentAndClass();
        $booking = $this->book($student, $class, BookingStatus::PendingPayment);

        $this->assertNull(DB::table('bookings')->where('id', $booking->id)->value('confirmed_flag'));

        $booking->update(['status' => BookingStatus::Confirmed]);

        $this->assertSame(1, DB::table('bookings')->where('id', $booking->id)->value('confirmed_flag'));
    }

    /** @return array{Student, TrialClass} */
    private function makeStudentAndClass(): array
    {
        $guardian = Guardian::create();
        $student = $guardian->students()->create(['name' => 'Test Kid', 'grade' => 3]);

        return [$student, $this->makeClass(Teacher::create())];
    }

    private function makeClass(Teacher $teacher, array $overrides = []): TrialClass
    {
        return TrialClass::create($overrides + [
            'teacher_id' => $teacher->id,
            'subject' => 'math',
            'title' => 'Test Class',
            'starts_at' => now()->addDay(),
            'price_cents' => 1500,
        ]);
    }

    private function book(Student $student, TrialClass $class, BookingStatus $status): Booking
    {
        return Booking::create(['student_id' => $student->id, 'trial_class_id' => $class->id, 'status' => $status]);
    }

    /** Bypasses the enum cast so the database CHECK is what gets tested. */
    private function rawBooking(Student $student, TrialClass $class, string $status): void
    {
        DB::table('bookings')->insert([
            'student_id' => $student->id,
            'trial_class_id' => $class->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pay(Booking $booking, string $key): PaymentAttempt
    {
        return PaymentAttempt::create([
            'booking_id' => $booking->id,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 1500,
            'idempotency_key' => $key,
        ]);
    }
}
