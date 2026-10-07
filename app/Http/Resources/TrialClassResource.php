<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TrialClass */
class TrialClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toIso8601String(),
            'capacity' => $this->capacity,
            'price_cents' => $this->price_cents,
            'teacher' => ['id' => $this->teacher_id, 'name' => $this->teacher->user?->name],
            'seats_taken' => $this->seats_taken,              // confirmed
            'seats_held' => $this->seats_held,                // hold mode: booked, awaiting payment
            'seats_available' => max(0, $this->capacity - $this->seats_taken - $this->seats_held),
            'is_full' => $this->seats_taken + $this->seats_held >= $this->capacity,
            // Only with ?student_id=: that child's CONFIRMED / PENDING_PAYMENT booking here, else null.
            'student_booking_status' => $this->when($request->has('student_id'), fn () => $this->student_booking_status),
        ];
    }
}
