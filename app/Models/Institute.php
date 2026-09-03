<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institute extends Model
{
    protected $fillable = ['name'];

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function coordinators(): HasMany
    {
        return $this->hasMany(User::class)->whereHas(
            'role',
            fn ($q) => $q->where('name', Role::COORDINATOR),
        );
    }
}
