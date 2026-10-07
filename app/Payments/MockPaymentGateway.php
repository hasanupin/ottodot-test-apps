<?php

namespace App\Payments;

/** Stand-in for a real gateway: optional delay, success unless told to decline. */
class MockPaymentGateway implements PaymentGateway
{
    public function charge(int $amountCents, string $simulate = 'success', int $delayMs = 0): ChargeResult
    {
        usleep(max(0, min($delayMs, 5000)) * 1000);

        return $simulate === 'decline' ? new ChargeResult(false, 'card_declined') : new ChargeResult(true);
    }

    public function refund(int $amountCents): void {}
}
