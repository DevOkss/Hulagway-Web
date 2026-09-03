<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyQuestion extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SINGLE_CHOICE = 'single_choice';

    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';

    public const TYPE_DROPDOWN = 'dropdown';

    public const TYPE_DATE = 'date';

    public const TYPE_LIKERT = 'likert';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_SINGLE_CHOICE,
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_DROPDOWN,
        self::TYPE_DATE,
        self::TYPE_LIKERT,
    ];

    public const DATA_SCOPE_INDIVIDUAL = 'individual';
    public const DATA_SCOPE_HOUSEHOLD = 'household';
    public const DATA_SCOPE_RESPONSE = 'response';
    public const DATA_SCOPE_LOCATION = 'location';
    public const DATA_SCOPES = [
        self::DATA_SCOPE_INDIVIDUAL,
        self::DATA_SCOPE_HOUSEHOLD,
        self::DATA_SCOPE_RESPONSE,
        self::DATA_SCOPE_LOCATION,
    ];

    protected $fillable = [
        'survey_id',
        'question_text',
        'type',
        'is_required',
        'order',
        'code',
        'data_scope',
        'map_enabled',
        'options_json',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'order' => 'integer',
            'map_enabled' => 'boolean',
            'options_json' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(SurveyOption::class)->orderBy('order');
    }
}
