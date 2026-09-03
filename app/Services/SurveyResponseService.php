<?php

namespace App\Services;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SurveyResponseService
{
    /**
     * Store a response with its answers. Idempotent via response uuid.
     * Household per response: one survey submission = one household (Silanga/Maquilao pattern).
     * Barangay is mandatory, purok is required input (not aggregated per spec).
     *
     * @param  array<string, mixed>  $data  answers: [question_id => scalar|array], household, household_members, purok
     */
    public function store(Survey $survey, array $data): SurveyResponse
    {
        if ($survey->status !== Survey::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['survey' => 'This survey is not accepting responses.']);
        }

        // Household surveys: barangay + purok + household required; Generic: optional
        $isHousehold = ($survey->type ?? 'generic') === \App\Models\Survey::TYPE_HOUSEHOLD;
        $barangayId = $data['barangay_id'] ?? $survey->barangay_id;
        if ($isHousehold && ! $barangayId) {
            throw ValidationException::withMessages(['barangay_id' => 'Barangay is required.']);
        }
        $purok = $data['purok'] ?? $data['household']['purok'] ?? null;
        if ($isHousehold && ! $purok) {
            throw ValidationException::withMessages(['purok' => 'Purok is required.']);
        }

        return DB::transaction(function () use ($survey, $data, $barangayId, $purok, $isHousehold) {
            // One household per response for household surveys only
            $householdData = $data['household'] ?? null;
            // Auto-derive Head of Family from roster if not provided (per latest UI spec removal)
            if ($isHousehold && $householdData && empty($householdData['head_name'])) {
                $firstMember = $data['household_members'][0] ?? $data['members'][0] ?? null;
                if ($firstMember && !empty($firstMember['name'])) {
                    $householdData['head_name'] = $firstMember['name'];
                }
            }
            $household = null;
            if ($isHousehold && $householdData) {
                $household = \App\Models\Household::create([
                    'barangay_id' => $barangayId,
                    'purok' => $purok,
                    'household_code' => $householdData['household_code'] ?? null,
                    'address' => $householdData['address'] ?? null,
                    'head_name' => $householdData['head_name'] ?? 'Unknown',
                    'contact_no' => $householdData['contact_no'] ?? null,
                    'live_in_status' => $householdData['live_in_status'] ?? $householdData['live_in'] ?? null,
                    'live_in_years' => $householdData['live_in_years'] ?? null,
                    'live_in_reason' => $householdData['live_in_reason'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'created_by' => $data['user_id'] ?? null,
                ]);
                // Auto-generate household_code if not provided
                if (! $household->household_code) {
                    $household->update(['household_code' => 'HH-'.$household->id.'-'.strtoupper(substr(md5($household->id.time()),0,6))]);
                }
            }

            $response = SurveyResponse::create([
                'uuid' => $data['uuid'] ?? (string) str()->uuid(),
                'survey_id' => $survey->id,
                'barangay_id' => $barangayId,
                'purok' => $purok,
                'household_id' => $household?->id,
                'user_id' => $data['user_id'] ?? null,
                'respondent_data' => $data['respondent_data'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'source' => $data['source'] ?? SurveyResponse::SOURCE_PUBLIC,
                'submitted_at' => $data['submitted_at'] ?? now(),
            ]);

            // Household members roster — only for household surveys
            // Per new spec, each member carries: civil_status, PWD, mentally_challenged, OSY (+ last grade if youth), senior auto 60+, bedridden (3 options default No), pregnant if female
            if ($isHousehold) {
                $members = $data['household_members'] ?? $data['members'] ?? [];
                foreach ($members as $member) {
                    if (empty($member['name'])) continue;
                    $age = $member['age'] ?? null;
                    $isSenior = $age !== null && $age !== '' ? ((int) $age >= 60) : false;
                    // Normalize bedridden_status default No, allowed: No, Yes - Mabakod pa, Yes - Di na kabakod
                    $bedridden = $member['bedridden_status'] ?? $member['bedridden'] ?? 'No';
                    if (! in_array($bedridden, ['No', 'Yes - Mabakod pa', 'Yes - Di na kabakod'], true)) {
                        $bedridden = $bedridden === 'Yes' ? 'Yes - Di na kabakod' : 'No';
                    }
                    $toBool = function($v) {
                        if ($v === null) return null;
                        if (is_bool($v)) return $v;
                        if (is_int($v)) return $v === 1;
                        $s = strtolower(trim((string) $v));
                        if (in_array($s, ['yes','y','true','1','oo'], true)) return true;
                        if (in_array($s, ['no','n','false','0','dili'], true)) return false;
                        return (bool) $v;
                    };
                    $normalizePwd = function($v) {
                        $allowed = ['No','Yes - Makalakaw pa','Yes - Di na ka lakaw'];
                        if ($v === null || $v === '') return 'No';
                        $s = trim((string) $v);
                        if (in_array($s, $allowed, true)) return $s;
                        // boolean / Yes fallback
                        $lower = strtolower($s);
                        if (in_array($lower, ['yes','true','1','y','oo'], true)) return 'Yes - Di na ka lakaw';
                        if (in_array($lower, ['no','false','0','n','dili'], true)) return 'No';
                        return 'No';
                    };
                    $normalizeMentally = function($v) {
                        $allowed = ['No','Yes - dili problema sa katilingban','Yes - hasol sa katilingban'];
                        if ($v === null || $v === '') return 'No';
                        $s = trim((string) $v);
                        if (in_array($s, $allowed, true)) return $s;
                        $lower = strtolower($s);
                        if (in_array($lower, ['yes','true','1','y','oo'], true)) return 'Yes - hasol sa katilingban';
                        if (in_array($lower, ['no','false','0','n','dili'], true)) return 'No';
                        return 'No';
                    };
                    $response->household?->members()->create([
                        'survey_response_id' => $response->id,
                        'name' => $member['name'],
                        'age' => $age,
                        'sex' => $member['sex'] ?? null,
                        'civil_status' => $member['civil_status'] ?? null,
                        'relationship' => $member['relationship'] ?? null,
                        'is_head' => (bool) ($member['is_head'] ?? false),
                        'is_pwd' => $normalizePwd($member['is_pwd'] ?? $member['pwd'] ?? 'No'),
                        'is_mentally_challenged' => $normalizeMentally($member['is_mentally_challenged'] ?? $member['mentally_challenged'] ?? 'No'),
                        'is_osy' => array_key_exists('is_osy', $member) ? $toBool($member['is_osy']) : (array_key_exists('osy', $member) ? $toBool($member['osy']) : null),
                        'osy_last_grade' => $member['osy_last_grade'] ?? $member['last_grade'] ?? null,
                        'bedridden_status' => $bedridden,
                        'is_pregnant' => $toBool($member['is_pregnant'] ?? $member['pregnant'] ?? false) ?? false,
                        'is_senior' => $isSenior,
                        'lcr_registered' => in_array($member['lcr_registered'] ?? 'No', ['Yes','No'], true) ? $member['lcr_registered'] : 'No',
                        'lcr_reason' => ($member['lcr_registered'] ?? 'No') === 'No' ? ($member['lcr_reason'] ?? null) : null,
                        'katungdanan_status' => in_array($member['katungdanan_status'] ?? $member['katungdanan'] ?? 'No', ['Yes','No'], true) ? ($member['katungdanan_status'] ?? $member['katungdanan'] ?? 'No') : 'No',
                        'katungdanan_position' => ($member['katungdanan_status'] ?? $member['katungdanan'] ?? 'No') === 'Yes' ? ($member['katungdanan_position'] ?? null) : null,
                    ]);
                    if (! $household) {
                        $household = \App\Models\Household::create([
                            'barangay_id' => $barangayId,
                            'purok' => $purok,
                            'head_name' => $member['name'],
                            'created_by' => $data['user_id'] ?? null,
                        ]);
                        $response->update(['household_id' => $household->id]);
                    }
                }
            }

            // Answers: household/response scope -> single row per question; individual scope -> per-member rows
            $memberAnswers = $data['member_answers'] ?? [];
            $memberByName = collect($response->household?->members ?? [])->keyBy('name');
            foreach ($data['answers'] as $questionId => $answer) {
                $question = \App\Models\SurveyQuestion::find($questionId);
                $isIndividual = $question && $question->data_scope === \App\Models\SurveyQuestion::DATA_SCOPE_INDIVIDUAL;
                if ($isIndividual && isset($memberAnswers[$questionId]) && is_array($memberAnswers[$questionId])) {
                    // Per-member answers: { memberName|memberId => value }
                    foreach ($memberAnswers[$questionId] as $memberKey => $memberValue) {
                        $member = $memberByName->get($memberKey) ?? $response->household?->members()->where('id', $memberKey)->first();
                        $memberId = $member?->id ?? (is_numeric($memberKey) ? (int) $memberKey : null);
                        // Skip if member not found and key is not numeric id
                        if (! $member && ! is_numeric($memberKey)) {
                            // Try to find by name
                            $member = $response->household?->members()->where('name', $memberKey)->first();
                            $memberId = $member?->id;
                        }
                        if ($memberValue === null || $memberValue === '' || (is_array($memberValue) && empty($memberValue))) continue;
                        // Block negative numbers for number-type questions
                        if ($question && $question->type === \App\Models\SurveyQuestion::TYPE_NUMBER && is_numeric($memberValue) && (float) $memberValue < 0) {
                            $memberValue = 0;
                        }
                        $response->answers()->create([
                            'survey_question_id' => $questionId,
                            'household_member_id' => $memberId,
                            'answer' => is_array($memberValue) ? json_encode(array_values($memberValue)) : $memberValue,
                        ]);
                    }
                } else {
                    // Household/response scope or legacy flat individual (single Yes/No per household) — keep single row
                    if ($answer === null || $answer === '' || (is_array($answer) && empty($answer))) continue;
                    // Block negative numbers for number-type questions
                    if ($question && $question->type === \App\Models\SurveyQuestion::TYPE_NUMBER && is_numeric($answer) && (float) $answer < 0) {
                        $answer = 0;
                    }
                    $response->answers()->create([
                        'survey_question_id' => $questionId,
                        'household_member_id' => null,
                        'answer' => is_array($answer) ? json_encode(array_values($answer)) : $answer,
                    ]);
                }
            }

            return $response->load(['answers', 'household.members']);
        });
    }

    /**
     * Aggregate published-survey answers by question for the responses viewer.
     * Individual-scope: rows are per-member (persons); total_answered = distinct persons for individual, responses for household/response.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summarizeByQuestion(Survey $survey): array
    {
        $questions = $survey->questions()->with('options')->get();

        return $questions->map(function ($question) {
            $counts = [];
            $isIndividual = $question->data_scope === \App\Models\SurveyQuestion::DATA_SCOPE_INDIVIDUAL;
            $isHousehold = $question->data_scope === \App\Models\SurveyQuestion::DATA_SCOPE_HOUSEHOLD;
            if (in_array($question->type, ['single_choice', 'multiple_choice', 'dropdown', 'likert'])) {
                $raw = DB::table('survey_answers')
                    ->join('survey_responses', 'survey_responses.id', '=', 'survey_answers.survey_response_id')
                    ->where('survey_answers.survey_question_id', $question->id)
                    ->when($isIndividual, fn($q) => $q->whereNotNull('survey_answers.household_member_id'))
                    ->pluck('survey_answers.answer');

                foreach ($question->options as $option) {
                    $counts[$option->label] = 0;
                }

                foreach ($raw as $value) {
                    foreach ((array) json_decode($value, true) ?: [$value] as $label) {
                        $label = (string) $label;
                        $counts[$label] = ($counts[$label] ?? 0) + 1;
                    }
                }
            }

            $totalQuery = DB::table('survey_answers')->where('survey_question_id', $question->id);
            if ($isIndividual) {
                $totalAnswered = (clone $totalQuery)->whereNotNull('household_member_id')->distinct()->count('household_member_id');
            } elseif ($isHousehold) {
                $totalAnswered = DB::table('survey_answers')
                    ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                    ->where('survey_answers.survey_question_id', $question->id)
                    ->distinct()->count('survey_responses.household_id');
            } else {
                $totalAnswered = (clone $totalQuery)->count();
            }

            // For text/textarea/number/date, collect recent text answers (not just counts)
            $textAnswers = null;
            if (in_array($question->type, ['text', 'textarea', 'number', 'date'])) {
                $textAnswers = DB::table('survey_answers')
                    ->join('survey_responses', 'survey_responses.id', '=', 'survey_answers.survey_response_id')
                    ->where('survey_answers.survey_question_id', $question->id)
                    ->orderByDesc('survey_answers.id')
                    ->limit(50)
                    ->pluck('survey_answers.answer')
                    ->filter(fn($v) => $v !== null && $v !== '')
                    ->values()
                    ->all();
            }

            return [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'type' => $question->type,
                'is_required' => $question->is_required,
                'data_scope' => $question->data_scope,
                'code' => $question->code,
                'total_answered' => $totalAnswered,
                'option_counts' => $counts ?: null,
                'text_answers' => $textAnswers,
            ];
        })->all();
    }
}
