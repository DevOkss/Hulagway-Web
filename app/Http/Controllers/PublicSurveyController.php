<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Services\SurveyResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicSurveyController extends Controller
{
    public function __construct(private readonly SurveyResponseService $responses) {}

    /**
     * Guest-facing survey page, accessed via unguessable public link.
     */
    public function show(string $token): Response
    {
        $survey = Survey::where('public_token', $token)
            ->where('status', Survey::STATUS_PUBLISHED)
            ->with(['questions.options', 'barangay:id,name'])
            ->firstOrFail();

        return Inertia::render('Public/SurveyAnswer', [
            'token' => $token,
            'survey' => $survey,
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $survey = Survey::where('public_token', $token)
            ->where('status', Survey::STATUS_PUBLISHED)
            ->firstOrFail();

        $isHousehold = ($survey->type ?? 'generic') === \App\Models\Survey::TYPE_HOUSEHOLD;
        $includeBarangay = (bool) ($survey->include_barangay ?? $isHousehold);
        $validated = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'barangay_id' => [$includeBarangay ? 'required' : 'nullable', 'exists:barangays,id'],
            'purok' => [$isHousehold ? 'required' : 'nullable', 'string', 'max:50'],
            'household' => [$isHousehold ? 'required' : 'nullable', 'array'],
            'household.head_name' => [$isHousehold ? 'required' : 'nullable', 'string', 'max:255'],
            'household.purok' => ['nullable', 'string', 'max:50'],
            'household.address' => ['nullable', 'string', 'max:255'],
            'household.contact_no' => ['nullable', 'string', 'max:20'],
            'household.live_in_status' => ['nullable', 'string', 'in:Yes,No'],
            'household.live_in_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'household.live_in_reason' => ['nullable', 'string', 'max:500'],
            'household_members' => $isHousehold ? ['required','array','min:1'] : ['nullable','array'],
            'household_members.*.name' => [$isHousehold ? 'required_with:household_members' : 'nullable', 'string', 'max:255'],
            'household_members.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'household_members.*.sex' => ['nullable', 'string', 'in:Male,Female,Others'],
            'household_members.*.civil_status' => ['nullable', 'string', 'max:30'],
            'household_members.*.relationship' => ['nullable', 'string', 'max:50'],
            'household_members.*.is_head' => ['nullable', 'boolean'],
            'household_members.*.is_pwd' => ['nullable'],
            'household_members.*.is_mentally_challenged' => ['nullable'],
            'household_members.*.is_osy' => ['nullable'],
            'household_members.*.osy_last_grade' => ['nullable', 'string', 'max:100'],
            'household_members.*.bedridden_status' => ['nullable', 'string', 'max:50'],
            'household_members.*.is_pregnant' => ['nullable'],
            'household_members.*.lcr_registered' => ['nullable', 'string', 'in:Yes,No'],
            'household_members.*.lcr_reason' => ['nullable', 'string', 'max:500'],
            'household_members.*.katungdanan_status' => ['nullable', 'string', 'in:Yes,No'],
            'household_members.*.katungdanan_position' => ['nullable', 'string', 'max:255'],
            'respondent_data' => ['nullable', 'array'],
            'answers' => ['required', 'array'],
            'member_answers' => ['nullable', 'array'],
            'member_answers.*' => ['array'],
        ]);

        $this->enforceRequiredQuestions($survey, $validated['answers'], $validated['member_answers'] ?? []);

        $this->responses->store($survey, $validated + [
            'source' => SurveyResponse::SOURCE_PUBLIC,
            'member_answers' => $validated['member_answers'] ?? $request->input('member_answers', []),
        ]);

        return redirect()
            ->route('public-surveys.show', $token)
            ->with('success', 'Thank you! Your response has been recorded.');
    }

    /**
     * @param  array<int|string, mixed>  $answers
     * @param  array<int|string, mixed>  $memberAnswers
     */
    private function enforceRequiredQuestions(Survey $survey, array $answers, array $memberAnswers = []): void
    {
        $required = $survey->questions()->where('is_required', true)->get(['id', 'data_scope']);
        $missing = $required->reject(function ($q) use ($answers, $memberAnswers) {
            $id = $q->id;
            if ($q->data_scope === \App\Models\SurveyQuestion::DATA_SCOPE_INDIVIDUAL) {
                // Individual requires at least one member answer with non-empty value
                if (! isset($memberAnswers[$id]) || ! is_array($memberAnswers[$id])) {
                    return true;
                }
                foreach ($memberAnswers[$id] as $v) {
                    if ($v !== null && $v !== '' && !(is_array($v) && empty($v))) {
                        return false;
                    }
                }
                // Also allow legacy flat answers[id] for backward compatibility
                return ! (isset($answers[$id]) && $answers[$id] !== null && $answers[$id] !== '' && !(is_array($answers[$id]) && empty($answers[$id])));
            }
            return ! (isset($answers[$id]) && $answers[$id] !== null && $answers[$id] !== '' && !(is_array($answers[$id]) && empty($answers[$id])));
        })->pluck('id');

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'answers' => 'Please answer all required questions.',
            ]);
        }
    }
}
