<?php

namespace App\Exceptions;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A request that is valid but breaks a business rule (e.g. deleting a teacher who still has classes). */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, private int $status = 409, private array $errors = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return Controller::failed($this->getMessage(), $this->status, $this->errors);
    }
}
