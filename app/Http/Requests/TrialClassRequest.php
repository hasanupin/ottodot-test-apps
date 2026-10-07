<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by store (POST) and update (PUT). */
class TrialClassRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'subject' => ['required', 'in:math,science'],
            'title' => ['required', 'string', 'max:255'],
            // New classes must be in the future; an existing one may be edited after it started.
            'starts_at' => array_filter(['required', 'date', $this->isMethod('post') ? 'after:now' : null]),
            'capacity' => ['required', 'integer', 'between:1,4'],
            'price_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
