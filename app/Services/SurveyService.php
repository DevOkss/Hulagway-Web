<?php

namespace App\Services;

use App\Models\Survey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SurveyService
{
    /**
     * Create a survey with questions and options in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): Survey
    {
        return DB::transaction(function () use ($data, $userId) {
            $isGeneric = ($data['type'] ?? null) === Survey::TYPE_GENERIC;
            if ($isGeneric) {
                $includeBarangay = (bool) ($data['include_barangay'] ?? false);
            } else {
                // Household: city-wide if no barangay selected
                $includeBarangay = !empty($data['barangay_id']);
            }
            $survey = Survey::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? Survey::TYPE_GENERIC,
                'status' => Survey::STATUS_DRAFT,
                'barangay_id' => $includeBarangay ? ($data['barangay_id'] ?? null) : null,
                'include_barangay' => $includeBarangay,
                'created_by' => $userId,
            ]);

            // Household surveys auto-use standardized template (Maquilao-Purok 4 structure)
            $questions = $data['questions'] ?? [];
            if (($data['type'] ?? null) === Survey::TYPE_HOUSEHOLD && empty($questions)) {
                $questions = $this->householdTemplate();
            }

            $this->syncQuestions($survey, $questions);

            return $survey->load('questions.options');
        });
    }

    public function update(Survey $survey, array $data): Survey
    {
        DB::transaction(function () use ($survey, $data) {
            if ($survey->status === Survey::STATUS_ARCHIVED) {
                throw ValidationException::withMessages([
                    'survey' => 'Archived surveys cannot be edited. Restore it first.',
                ]);
            }
            if ($survey->status === Survey::STATUS_PUBLISHED && ($data['questions'] ?? false)) {
                throw ValidationException::withMessages([
                    'questions' => 'Questions cannot be modified while the survey is published.',
                ]);
            }

            $isGeneric = ($data['type'] ?? $survey->type) === Survey::TYPE_GENERIC;
            if ($isGeneric) {
                $includeBarangay = array_key_exists('include_barangay', $data) ? (bool) $data['include_barangay'] : (bool) $survey->include_barangay;
            } else {
                // Household: determine by provided barangay_id, or keep existing if not provided
                if (array_key_exists('barangay_id', $data)) {
                    $includeBarangay = !empty($data['barangay_id']);
                } else {
                    $includeBarangay = (bool) $survey->include_barangay;
                }
            }
            $survey->update([
                'title' => $data['title'] ?? $survey->title,
                'description' => $data['description'] ?? $survey->description,
                'type' => $data['type'] ?? $survey->type,
                'barangay_id' => $includeBarangay ? ($data['barangay_id'] ?? $survey->barangay_id) : null,
                'include_barangay' => $includeBarangay,
            ]);

            if (array_key_exists('questions', $data) && $survey->status !== Survey::STATUS_PUBLISHED) {
                $this->syncQuestions($survey, $data['questions']);
            }
        });

        return $survey->fresh(['questions.options']);
    }

    public function publish(Survey $survey): Survey
    {
        if ($survey->status === Survey::STATUS_ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => 'Archived surveys cannot be published. Restore it first.',
            ]);
        }
        if ($survey->status === Survey::STATUS_PUBLISHED) {
            return $survey;
        }

        if ($survey->questions()->count() === 0) {
            throw ValidationException::withMessages([
                'status' => 'Add at least one question before publishing.',
            ]);
        }

        $survey->update([
            'status' => Survey::STATUS_PUBLISHED,
            'published_at' => now(),
            'public_token' => $survey->public_token ?? bin2hex(random_bytes(16)),
        ]);

        return $survey;
    }

    public function deactivate(Survey $survey): Survey
    {
        $survey->update(['status' => Survey::STATUS_DEACTIVATED]);

        return $survey;
    }

    public function archive(Survey $survey): Survey
    {
        if ($survey->status === Survey::STATUS_ARCHIVED) {
            return $survey;
        }

        $survey->update(['status' => Survey::STATUS_ARCHIVED]);

        return $survey;
    }

    public function restore(Survey $survey): Survey
    {
        if ($survey->status !== Survey::STATUS_ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => 'Only archived surveys can be restored.',
            ]);
        }

        $survey->update(['status' => Survey::STATUS_DEACTIVATED]);

        return $survey;
    }

    /**
     * Replace all questions/options of a draft survey.
     * Hulagway codes: seniors_90, bedridden_cat2, mentally_cat2, pwd_cat2, osy, live_in, poor_cat2, pregnant, etc.
     */
    private function syncQuestions(Survey $survey, array $questions): void
    {
        // Fail fast on duplicate codes within the same payload (per-survey unique)
        $codes = array_filter(array_map(fn($q) => $q['code'] ?? null, $questions));
        $dupCodes = array_filter(array_count_values($codes), fn($c) => $c > 1);
        if ($dupCodes) {
            throw ValidationException::withMessages([
                'questions' => 'Duplicate hulagway codes within this survey: '.implode(', ', array_keys($dupCodes)).'. Each code must be unique per survey.',
            ]);
        }
        $keepIds = [];

        foreach ($questions as $index => $questionData) {
            // Guard: prevent "Group - Label" duplication if admin pastes "PWD - PWD"
            $rawText = trim((string) ($questionData['question_text'] ?? ''));
            if (isset($questionData['group']) && trim(strtolower((string) $questionData['group'])) === trim(strtolower($rawText))) {
                $rawText = $questionData['question_text'];
            } elseif (preg_match('/^(?:\d+\.\s*)?(.+?)\s*-\s*\1(\?)?$/i', $rawText, $m)) {
                $rawText = trim($m[1]) . ($m[2] ?? '');
            }
            $question = $survey->questions()->updateOrCreate(
                ['id' => $questionData['id'] ?? null],
                [
                    'question_text' => $rawText,
                    'type' => $questionData['type'],
                    'is_required' => (bool) ($questionData['is_required'] ?? false),
                    'order' => $questionData['order'] ?? $index,
                    'code' => $questionData['code'] ?? null,
                    'data_scope' => $questionData['data_scope'] ?? 'response',
                    'map_enabled' => (bool) ($questionData['map_enabled'] ?? true),
                    'options_json' => isset($questionData['options']) ? json_encode(array_values($questionData['options'])) : null,
                ],
            );

            $optionIds = [];
            foreach ($questionData['options'] ?? [] as $optionIndex => $optionItem) {
                $optionLabel = is_array($optionItem) ? ($optionItem['label'] ?? $optionItem['value'] ?? '') : $optionItem;
                $optionId = is_array($optionItem) ? ($optionItem['id'] ?? null) : null;
                $option = $question->options()->updateOrCreate(
                    ['id' => $optionId],
                    [
                        'label' => $optionLabel,
                        'order' => $optionIndex,
                    ],
                );
                $optionIds[] = $option->id;
            }

            // Remove dropped options
            if ($optionIds === []) {
                $question->options()->delete();
            } else {
                $question->options()->whereNotIn('id', $optionIds)->delete();
            }

            $keepIds[] = $question->id;
        }

        if ($keepIds === []) {
            $survey->questions()->delete();
        } else {
            $survey->questions()->whereNotIn('id', $keepIds)->delete();
        }
    }

    /**
     * Standardized Community Household Survey template (Maquilao-Purok 4 — per updated spec).
     * Household-level questions only; per-member attributes (civil_status, PWD, mentally_challenged,
     * OSY + last grade, senior auto 60+, bedridden 3 options, pregnant if female) are embedded
     * under each family member via household_members columns, not as separate questions.
     */
    private function householdTemplate(): array
    {
        return [
            // Household identification handled via household.purok + barangay_id and member roster Head; no duplicate Household Head / Purok questions below roster (per latest UI spec)
            // Household Conditions
            ['question_text' => 'Housing Condition', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'housing', 'options' => ['Good', 'Needs Repair', 'Poor']],
            ['question_text' => 'Land Ownership', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'land', 'options' => ['Owned', 'Rented', 'Informal Settler']],
            ['question_text' => 'Electricity Access', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'electricity', 'options' => ['Yes', 'No - Can Afford', 'No - Cannot Afford']],
            ['question_text' => 'Water Access', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'water', 'options' => ['Piped', 'Well', 'None - Nearby Source', 'None - Far']],
            ['question_text' => 'Toilet/Sanitation', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'toilet', 'options' => ['Flush', 'Pit', 'None', 'Communal']],
            ['question_text' => 'Livelihood', 'type' => 'text', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => false, 'code' => null, 'options' => []],
            ['question_text' => 'Poor Family Category', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'poor_cat2', 'options' => ['Category 1 – Lisod pero naay source', 'Category 2 – Perting lisura', 'Not Applicable']],
            // Live-in household-level (conditional follow-ups)
            ['question_text' => 'Live-in Status (Nag-live-in ba?)', 'type' => 'single_choice', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => true, 'code' => 'live_in', 'options' => ['Yes', 'No']],
            ['question_text' => 'If Live-in Yes: How many years living together?', 'type' => 'number', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => false, 'code' => 'live_in_years', 'options' => []],
            ['question_text' => 'If Live-in Yes: Reason why not married?', 'type' => 'text', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => false, 'code' => 'live_in_reason', 'options' => []],
            // Government services - household-level below roster (outside member fields)
            ['question_text' => 'Government Service You Like', 'type' => 'text', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => false, 'code' => 'gov_service_like', 'options' => []],
            ['question_text' => 'Government Service Satisfaction', 'type' => 'likert', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => false, 'code' => 'gov_satisfaction', 'options' => ['1', '2', '3', '4', '5']],
            ['question_text' => 'Suggestions', 'type' => 'text', 'is_required' => false, 'data_scope' => 'household', 'map_enabled' => false, 'code' => 'suggestions', 'options' => []],
        ];
    }
}
