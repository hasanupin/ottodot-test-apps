<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** No parent_id: the parent comes from the session; BookingService checks the child belongs to them. */
class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'trial_class_id' => ['required', 'integer', 'exists:trial_classes,id'],
        ];
    }
}
