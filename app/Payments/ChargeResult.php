<?php

namespace App\Payments;

final class ChargeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $failureReason = null,  // 'card_declined' when declined
    ) {}
}
