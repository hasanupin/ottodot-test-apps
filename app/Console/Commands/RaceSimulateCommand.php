<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Student;
use App\Models\TrialClass;
use App\Services\BookingService;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The last-seat race for real: one OS process per parent, all booking and paying at the same instant.
 * hold mode: the seat is decided at booking, losers get class_full and are never charged.
 * first_to_pay: everyone books, the seat is decided at payment. --delay slows the mock gateway, widening the window
 * between pre-check and final check so "charged then refunded" shows up.
 */
class RaceSimulateCommand extends Command
{
    protected $signature = 'race:simulate {--class=} {--parents=5} {--delay=500}';

    protected $description = 'Several parents pay for the last seat of a class at the same moment (separate processes)';

    public function handle(BookingService $bookings): int
    {
        $class = $this->option('class')
            ? TrialClass::find($this->option('class'))
            : TrialClass::where('title', 'Math Explorers: Fractions')->first();

        if (! $class) {
            $this->error('Trial class not found.');

            return self::FAILURE;
        }
        if ($bookings->seatsTaken($class) !== $class->capacity - 1) {
            $this->error('Run ./scripts/reset.sh first (the class must have exactly one seat left).');

            return self::FAILURE;
        }

        $count = (int) $this->option('parents');
        $students = Student::whereDoesntHave('bookings', fn ($q) => $q->where('trial_class_id', $class->id))
            ->orderBy('id')
            ->limit($count)
            ->get();

        if ($students->count() < $count) {
            $this->error("Only {$students->count()} students have no booking in this class; --parents={$count} needs {$count}.");

            return self::FAILURE;
        }

        $startAt = sprintf('%.6f', microtime(true) + 1.5);
        $delay = (int) $this->option('delay');
        $mode = config('booking.mode');
        // Children use the caller's database (ottodot normally, ottodot_test under PHPUnit) and booking mode.
        $env = [
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'BOOKING_MODE' => $mode,
            'BOOKING_HOLD_MINUTES' => (string) config('booking.hold_minutes'),
        ];

        $this->info("Racing {$count} parents for the last seat of \"{$class->title}\" (mode {$mode}, gateway delay {$delay} ms)...");

        $results = Process::pool(function (Pool $pool) use ($students, $class, $startAt, $delay, $env) {
            foreach ($students as $student) {
                $pool->path(base_path())->env($env)->command([
                    'php', 'artisan', 'booking:book-and-pay', (string) $student->id, (string) $class->id,
                    '--key=race-'.Str::uuid(), "--delay={$delay}", "--start-at={$startAt}",
                ]);
            }
        })->start()->wait();

        foreach ($results as $result) {
            if (! $result->successful()) {
                $this->error(trim($result->errorOutput() ?: $result->output()));
            }
        }

        $racers = Booking::with('paymentAttempts')
            ->where('trial_class_id', $class->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        return $this->report($class, $students->load('guardian.user'), $racers, $bookings);
    }

    private function report(TrialClass $class, $students, $racers, BookingService $bookings): int
    {
        $this->table(['Booking', 'Student', 'Parent', 'Status', 'Payments'], $students->map(function (Student $s) use ($racers) {
            $b = $racers->get($s->id);

            return [
                $b?->id ?? '—',
                $s->name,
                $s->guardian->user?->name,
                $b?->status->value ?? 'rejected at booking (class_full)',
                $b?->paymentAttempts->sortBy('id')->map(fn ($p) => strtolower($p->status->value))->implode(' → ') ?: 'none',
            ];
        }));

        $confirmed = $bookings->seatsTaken($class);
        $winners = $racers->where('status', BookingStatus::Confirmed)->count();
        $rejected = $racers->filter(fn ($b) => $b->status === BookingStatus::FailedClassFull && $b->paymentAttempts->isEmpty())->count();
        $refunded = $racers->filter(fn ($b) => $b->paymentAttempts->contains('status', PaymentStatus::Refunded))->count();

        $atBooking = $students->count() - $racers->count();

        $this->line("Confirmed seats: {$confirmed}/{$class->capacity} · Winners this run: {$winners} · Rejected at booking: {$atBooking} · "
            ."Rejected before charge: {$rejected} · Charged then refunded: {$refunded}");

        // An invariant violation must be loud.
        if ($confirmed > $class->capacity || $winners !== 1) {
            $this->error('INVARIANT VIOLATED: expected exactly one winner and no overbooking.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
