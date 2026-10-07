<?php

namespace Tests\Support;

use App\Payments\ChargeResult;
use App\Payments\PaymentGateway;
use Closure;

/** Counts calls, ignores delays. The one-shot hook lets a test act "during" a charge (between pre-check and final check). */
class FakePaymentGateway implements PaymentGateway
{
    public int $charges = 0;

    public int $refunds = 0;

    public ?Closure $beforeChargeReturns = null;

    public function charge(int $amountCents, string $simulate = 'success', int $delayMs = 0): ChargeResult
    {
        $this->charges++;

        if ($hook = $this->beforeChargeReturns) {
            $this->beforeChargeReturns = null;   // cleared first: the hook may pay again through this gateway
            $hook();
        }

        return $simulate === 'decline' ? new ChargeResult(false, 'card_declined') : new ChargeResult(true);
    }

    public function refund(int $amountCents): void
    {
        $this->refunds++;
    }
}
