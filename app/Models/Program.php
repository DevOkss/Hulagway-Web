<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = ['institute_id', 'name', 'description'];

    public function institute(): BelongsTo
    {
        return $this->belongsTo(Institute::class);
    }

    public function extensionActivities(): HasMany
    {
        return $this->hasMany(ExtensionActivity::class);
    }
}
