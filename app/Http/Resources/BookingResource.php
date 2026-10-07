<?php

namespace App\Http\Resources;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'message' => self::messageFor($this->resource),
            'hold_expires_at' => $this->holdExpiresAt()?->toIso8601String(),   // null unless a hold-mode pending booking
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'student' => ['id' => $this->student_id, 'name' => $this->student->name],
            'parent' => ['id' => $this->student->parent_id, 'name' => $this->student->guardian->user?->name],
            'trial_class' => [
                'id' => $this->trial_class_id,
                'title' => $this->trialClass->title,
                'starts_at' => $this->trialClass->starts_at->toIso8601String(),
            ],
            'payment_attempts' => $this->whenLoaded('paymentAttempts', fn () => $this->paymentAttempts->map(fn ($p) => [
                'status' => $p->status->value,
                'amount_cents' => $p->amount_cents,
                'failure_reason' => $p->failure_reason,
                'created_at' => $p->created_at->toIso8601String(),
            ])),
        ];
    }

    /** Parent-facing outcome text. Also used as the response envelope's message. */
    public static function messageFor(Booking $booking): string
    {
        $refunded = $booking->paymentAttempts->contains('status', PaymentStatus::Refunded);

        return match ($booking->status) {
            BookingStatus::PendingPayment => ($until = $booking->holdExpiresAt())
                ? "Seat held for you until {$until->format('H:i:s')} UTC. Complete payment before then."
                : 'Booking created. Complete payment to secure the seat.',
            BookingStatus::Confirmed => "Booked! {$booking->student->name} is confirmed for {$booking->trialClass->title}.",
            BookingStatus::PaymentFailed => 'Payment was declined, so the seat was not booked. You can try booking again.',
            BookingStatus::FailedClassFull => $refunded
                ? 'Sorry, the last seat in this class was just taken. Your payment has been refunded.'
                : 'Sorry, the last seat in this class was just taken. You have not been charged.',
            BookingStatus::Cancelled => $refunded
                ? 'This child already has a confirmed seat in this class, so this payment was refunded.'
                : 'Booking cancelled.',
            BookingStatus::Expired => 'Your seat hold expired before payment, so the seat was released. You have not been charged.',
        };
    }
}
