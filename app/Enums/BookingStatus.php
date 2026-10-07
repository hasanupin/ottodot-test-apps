<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case Confirmed = 'CONFIRMED';
    case PaymentFailed = 'PAYMENT_FAILED';
    case FailedClassFull = 'FAILED_CLASS_FULL';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';   // hold mode: not paid within BOOKING_HOLD_MINUTES, seat released

    /** Terminal statuses cannot transition further. Only PendingPayment is non-terminal. */
    public function isTerminal(): bool
    {
        return $this !== self::PendingPayment;
    }
}
