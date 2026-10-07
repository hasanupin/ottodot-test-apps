<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'parent_id' => ['required', 'integer', 'exists:parents,id'],
            'name' => ['required', 'string', 'max:255'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
