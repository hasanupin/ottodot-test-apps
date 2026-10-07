<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// `parent` is a reserved word in PHP, so the model for the `parents` table is Guardian.
// Name and email live on the linked users row.
class Guardian extends Model
{
    protected $table = 'parents';

    protected $fillable = [];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'parent_id');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'parent_id');
    }
}
