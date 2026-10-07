<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingFilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'trial_class_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
        ];
    }
}
