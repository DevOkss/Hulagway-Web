<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Sdg extends Model
{
    protected $fillable = [
        'number',
        'code',
        'title',
        'short_title',
        'color',
        'icon_url',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }

    public function extensionActivities(): BelongsToMany
    {
        return $this->belongsToMany(ExtensionActivity::class, 'extension_activity_sdg');
    }
}
