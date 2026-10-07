<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'simulate' => ['sometimes', 'in:success,decline'],
            'delay_ms' => ['sometimes', 'integer', 'min:0', 'max:5000'],
        ];
    }
}
