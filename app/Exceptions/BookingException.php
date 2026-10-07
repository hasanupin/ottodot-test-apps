<?php

namespace App\Exceptions;

/** Booking rule violations. Renders {"errors": {"code": ["already_booked"]}} so clients can branch on the code. */
class BookingException extends BusinessRuleException
{
    public function __construct(public readonly string $errorCode, string $message, int $status = 409)
    {
        parent::__construct($message, $status, ['code' => [$errorCode]]);
    }

    public static function studentNotOwned(): self
    {
        return new self('student_not_owned', 'This child does not belong to the logged-in parent.', 403);
    }

    public static function alreadyBooked(): self
    {
        return new self('already_booked', 'This child already has a confirmed seat in this class.');
    }

    public static function classFull(): self
    {
        return new self('class_full', 'This class is full.');
    }

    public static function classStarted(): self
    {
        return new self('class_started', 'This class has already started.');
    }

    public static function notCancellable(): self
    {
        return new self('not_cancellable', 'Only bookings awaiting payment can be cancelled.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('idempotency_key_reused', 'This payment key was already used for a different booking.');
    }
}
