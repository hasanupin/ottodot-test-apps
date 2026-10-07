<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Teacher and parent accounts (store + update). The login details live on the linked users row. */
class AccountRequest extends FormRequest
{
    public function rules(): array
    {
        $account = $this->route('teacher') ?? $this->route('guardian');   // null on create

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account?->user?->id)],
            // Required on create; on update an empty password keeps the current one.
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'min:8'],
        ];
    }
}
