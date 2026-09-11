<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionActivityDailyTask extends Model
{
    protected $fillable = [
        'extension_activity_id',
        'title',
        'scheduled_date',
        'description',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ExtensionActivity::class, 'extension_activity_id');
    }
}
