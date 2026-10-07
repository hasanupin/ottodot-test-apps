<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Collection;

class PaymentService
{
    /** Admin only (route middleware). */
    public function list(array $filters): Collection
    {
        return PaymentAttempt::with(['booking.student', 'booking.trialClass'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['booking_id'] ?? null, fn ($q, $id) => $q->where('booking_id', $id))
            ->latest('id')
            ->get();
    }
}
