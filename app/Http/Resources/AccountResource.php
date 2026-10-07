<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A teacher or parent (Guardian) row with its login details from the linked users row. Never exposes the password.
 *
 * @mixin \App\Models\Teacher|\App\Models\Guardian
 */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user?->id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'students' => $this->whenLoaded('students', fn () => $this->students->map->only('id', 'name', 'grade')),
        ];
    }
}
