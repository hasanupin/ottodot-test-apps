<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PaymentAttempt — the idempotency key is internal and never exposed. */
class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount_cents' => $this->amount_cents,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at->toIso8601String(),
            'booking' => [
                'id' => $this->booking_id,
                'status' => $this->booking->status->value,
                'student' => ['id' => $this->booking->student_id, 'name' => $this->booking->student->name],
                'trial_class' => ['id' => $this->booking->trial_class_id, 'title' => $this->booking->trialClass->title],
            ],
        ];
    }
}
