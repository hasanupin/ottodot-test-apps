<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\TestCase;

/**
 * Truly parallel: race:simulate starts one OS process (own MySQL connection) per parent; each books and pays.
 * DatabaseMigrations, not RefreshDatabase: child processes can't see rows inside an uncommitted test transaction.
 */
#[Group('race')]
class RaceSimulationTest extends TestCase
{
    use BuildsSchoolData, DatabaseMigrations;

    public static function modes(): array
    {
        return ['first_to_pay' => ['first_to_pay'], 'hold' => ['hold']];
    }

    #[DataProvider('modes')]
    public function test_parallel_parents_for_the_last_seat_confirm_exactly_one(string $mode): void
    {
        config(['booking.mode' => $mode]);
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 3);
        foreach (range(1, 5) as $i) {
            $this->student($this->parentUser("Racer Parent {$i}"), "Racer {$i}");
        }

        $exit = Artisan::call('race:simulate', ['--class' => $class->id, '--parents' => 5, '--delay' => 300]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(4, $class->bookings()->where('status', BookingStatus::Confirmed)->count());

        $racers = $class->bookings()->whereHas('student', fn ($q) => $q->where('name', 'like', 'Racer %'))->get();
        $this->assertSame(1, $racers->where('status', BookingStatus::Confirmed)->count());

        if ($mode === 'hold') {
            // The seat was claimed at booking time: losers never got a booking, so nobody was charged or refunded.
            $this->assertCount(1, $racers);
            $this->assertSame(0, PaymentAttempt::where('status', PaymentStatus::Refunded)->count());
        } else {
            $this->assertCount(5, $racers);
            $this->assertSame(4, $racers->where('status', BookingStatus::FailedClassFull)->count());
        }

        foreach ($racers as $booking) {
            $succeeded = PaymentAttempt::where('booking_id', $booking->id)->where('status', PaymentStatus::Succeeded)->count();
            $refunded = PaymentAttempt::where('booking_id', $booking->id)->where('status', PaymentStatus::Refunded)->count();
            $this->assertLessThanOrEqual(1, $succeeded, "booking {$booking->id} charged twice");
            if ($booking->status !== BookingStatus::Confirmed) {
                $this->assertSame($succeeded, $refunded, "booking {$booking->id} charged without refund");
            }
        }
    }

    public function test_refuses_a_class_that_does_not_have_exactly_one_seat_left(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->fillSeats($class, 1);

        $this->assertSame(1, Artisan::call('race:simulate', ['--class' => $class->id]));
        $this->assertStringContainsString('Run ./scripts/reset.sh first', Artisan::output());
        $this->assertSame(1, Booking::count());
    }
}
