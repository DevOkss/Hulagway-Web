<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyResponse extends Model
{
    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_MOBILE = 'mobile';

    public const SOURCE_WEB = 'web';

    protected $fillable = [
        'uuid',
        'survey_id',
        'barangay_id',
        'purok',
        'household_id',
        'user_id',
        'respondent_data',
        'latitude',
        'longitude',
        'source',
        'submitted_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'respondent_data' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'submitted_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
