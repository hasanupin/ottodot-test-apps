<?php

namespace App\Payments;

interface PaymentGateway
{
    /** $simulate: 'success' | 'decline'. $delayMs mimics a slow real gateway (0..5000). */
    public function charge(int $amountCents, string $simulate = 'success', int $delayMs = 0): ChargeResult;

    public function refund(int $amountCents): void;
}
