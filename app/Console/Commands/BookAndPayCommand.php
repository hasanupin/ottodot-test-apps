<?php

namespace App\Console\Commands;

use App\Exceptions\BookingException;
use App\Models\Student;
use App\Services\BookingService;
use Illuminate\Console\Command;

/** One parent in its own process (own DB connection): book, then pay. Started in parallel by race:simulate. */
class BookAndPayCommand extends Command
{
    protected $signature = 'booking:book-and-pay {student} {class} {--key=} {--simulate=success} {--delay=0} {--start-at=}';

    protected $description = 'Book and pay a trial class (internal helper for race:simulate)';

    protected $hidden = true;

    public function handle(BookingService $bookings): int
    {
        // Wait until the shared start moment, so every process starts at once (after its PHP/Laravel boot).
        if ($startAt = $this->option('start-at')) {
            usleep((int) max(0, ((float) $startAt - microtime(true)) * 1_000_000));
        }

        $student = Student::findOrFail($this->argument('student'));

        try {
            // Hold mode decides the seat here; first_to_pay decides it in pay().
            $booking = $bookings->createBooking($student->parent_id, $student->id, (int) $this->argument('class'));
        } catch (BookingException $e) {
            $this->line(json_encode(['student_id' => $student->id, 'booking_id' => null, 'status' => "REJECTED:{$e->errorCode}"]));

            return self::SUCCESS;
        }

        $booking = $bookings->pay($booking, $this->option('key'), $this->option('simulate'), (int) $this->option('delay'));
        $this->line(json_encode(['student_id' => $student->id, 'booking_id' => $booking->id, 'status' => $booking->status->value]));

        return self::SUCCESS;
    }
}
