<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    // confirmed_flag is a DB-generated column: it is deliberately NOT fillable
    // and must never be written by the app (MySQL rejects writes to it).
    protected $fillable = ['student_id', 'trial_class_id', 'status', 'confirmed_at'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /** BOOKING_MODE=hold: a pending booking holds its seat for BOOKING_HOLD_MINUTES. */
    public static function holdsSeats(): bool
    {
        return config('booking.mode') === 'hold';
    }

    /** Holds created before this moment have expired. */
    public static function holdCutoff(): Carbon
    {
        return now()->subMinutes(config('booking.hold_minutes'));
    }

    // ponytail: derived from created_at, so changing BOOKING_HOLD_MINUTES also shifts live holds; store it if that matters.
    public function holdExpiresAt(): ?Carbon
    {
        return self::holdsSeats() && $this->status === BookingStatus::PendingPayment
            ? $this->created_at->copy()->addMinutes(config('booking.hold_minutes'))
            : null;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function trialClass(): BelongsTo
    {
        return $this->belongsTo(TrialClass::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
