<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionActivityDocument extends Model
{
    protected $fillable = [
        'extension_activity_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'activity_date',
        'progress',
        'caption',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'progress' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ExtensionActivity::class, 'extension_activity_id');
    }
}
