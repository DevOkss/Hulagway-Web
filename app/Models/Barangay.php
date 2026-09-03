<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $fillable = [
        'name',
        'population',
        'households',
        'latitude',
        'longitude',
        'boundary',
    ];

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function extensionActivities(): HasMany
    {
        return $this->hasMany(ExtensionActivity::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
