<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// Name and email live on the linked users row.
class Teacher extends Model
{
    protected $fillable = [];

    public function trialClasses(): HasMany
    {
        return $this->hasMany(TrialClass::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'teacher_id');
    }
}
