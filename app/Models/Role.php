<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const OFFICER = 'officer';

    public const COORDINATOR = 'coordinator';

    public const FIELD_PERSONNEL = 'field_personnel';

    public const LGU = 'lgu';

    public const MAYOR = 'mayor';

    public const GUEST = 'guest';

    protected $fillable = ['name', 'label'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
