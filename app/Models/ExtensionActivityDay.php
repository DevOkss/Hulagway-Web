<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionActivityDay extends Model
{
    protected $fillable = [
        'extension_activity_id',
        'day_number',
        'activity_date',
    ];

    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'activity_date' => 'date:Y-m-d',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ExtensionActivity::class, 'extension_activity_id');
    }
}
