<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdMember extends Model
{
    protected $fillable = [
        'household_id',
        'survey_response_id',
        'name',
        'age',
        'sex',
        'civil_status',
        'relationship',
        'is_head',
        'is_pwd',
        'is_mentally_challenged',
        'is_osy',
        'osy_last_grade',
        'bedridden_status',
        'is_pregnant',
        'is_senior',
        'lcr_registered',
        'lcr_reason',
        'katungdanan_status',
        'katungdanan_position',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'is_head' => 'boolean',
            // is_pwd and is_mentally_challenged now 3-option strings: No / Yes - Makalakaw pa / Yes - Di na ka lakaw etc.
            'is_osy' => 'boolean',
            'is_pregnant' => 'boolean',
            'is_senior' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(SurveyResponse::class, 'survey_response_id');
    }
}
