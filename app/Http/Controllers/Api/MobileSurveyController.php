<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Survey;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSurveyController extends Controller
{
    /**
     * Surveys assigned for field collection (published), with questions + options.
     */
    public function surveys(): JsonResponse
    {
        $surveys = Survey::where('status', Survey::STATUS_PUBLISHED)
            ->with(['questions.options:id,survey_question_id,label', 'barangay:id,name'])
            ->get(['id', 'title', 'description', 'type', 'barangay_id', 'published_at', 'created_at']);

        return response()->json([
            'surveys' => collect($surveys)->map(fn (Survey $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'type' => $s->type,
                'barangay_id' => $s->barangay_id,
                'include_barangay' => (bool) $s->include_barangay,
                'barangay_name' => $s->barangay?->name,
                'published_at' => $s->published_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
                'questions' => $s->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'type' => $q->type,
                    'is_required' => $q->is_required,
                    'order' => $q->order,
                    'code' => $q->code,
                    'data_scope' => $q->data_scope,
                    'map_enabled' => $q->map_enabled,
                    'options' => $q->options->pluck('label'),
                ]),
            ]),
        ]);
    }

    public function show(Survey $survey): JsonResponse
    {
        abort_unless($survey->status === Survey::STATUS_PUBLISHED, 404);

        return response()->json([
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'type' => $survey->type,
                'barangay_id' => $survey->barangay_id,
                'include_barangay' => (bool) $survey->include_barangay,
                'questions' => $survey->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'type' => $q->type,
                    'is_required' => $q->is_required,
                    'order' => $q->order,
                    'code' => $q->code,
                    'data_scope' => $q->data_scope,
                    'map_enabled' => $q->map_enabled,
                    'options' => $q->options->pluck('label'),
                ]),
            ],
        ]);
    }

    public function barangays(): JsonResponse
    {
        return response()->json([
            'barangays' => Barangay::orderBy('name')->get(['id', 'name', 'latitude', 'longitude']),
        ]);
    }

    /**
     * Submitted records online, grouped by survey — for field officers to view online.
     */
    public function submittedRecords(Request $request): JsonResponse
    {
        $surveys = \App\Models\Survey::where('status', \App\Models\Survey::STATUS_PUBLISHED)
            ->withCount('responses')
            ->with(['barangay:id,name'])
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'description', 'type', 'barangay_id', 'include_barangay', 'published_at', 'created_at']);

        $grouped = $surveys->map(function (\App\Models\Survey $survey) {
            $responses = \App\Models\SurveyResponse::where('survey_id', $survey->id)
                ->with(['household.members', 'barangay:id,name', 'answers.question'])
                ->orderByDesc('submitted_at')
                ->limit(50)
                ->get()
                ->map(function (\App\Models\SurveyResponse $r) use ($survey) {
                    $answers = $r->answers->map(function ($a) {
                        return [
                            'question_id' => $a->survey_question_id,
                            'question_text' => $a->question?->question_text ?? "Q{$a->survey_question_id}",
                            'question_code' => $a->question?->code,
                            'answer' => $a->answer,
                            'household_member_id' => $a->household_member_id,
                        ];
                    });
                    return [
                        'id' => $r->id,
                        'uuid' => $r->uuid,
                        'survey_id' => $r->survey_id,
                        'barangay' => $r->barangay?->name,
                        'barangay_id' => $r->barangay_id,
                        'purok' => $r->purok,
                        'household' => $r->household ? [
                            'id' => $r->household->id,
                            'head_name' => $r->household->head_name,
                            'purok' => $r->household->purok,
                            'contact_no' => $r->household->contact_no,
                            'live_in_status' => $r->household->live_in_status,
                            'live_in_years' => $r->household->live_in_years,
                            'live_in_reason' => $r->household->live_in_reason,
                            'members' => $r->household->members->map(fn($m) => [
                                'id' => $m->id,
                                'name' => $m->name,
                                'age' => $m->age,
                                'sex' => $m->sex,
                                'civil_status' => $m->civil_status,
                                'relationship' => $m->relationship,
                                'is_head' => $m->is_head,
                                'is_pwd' => $m->is_pwd,
                                'is_mentally_challenged' => $m->is_mentally_challenged,
                                'is_osy' => $m->is_osy,
                                'osy_last_grade' => $m->osy_last_grade,
                                'bedridden_status' => $m->bedridden_status,
                                'is_pregnant' => $m->is_pregnant,
                                'is_senior' => $m->is_senior,
                                'lcr_registered' => $m->lcr_registered,
                                'lcr_reason' => $m->lcr_reason,
                                'katungdanan_status' => $m->katungdanan_status,
                                'katungdanan_position' => $m->katungdanan_position,
                            ]),
                        ] : null,
                        'answers' => $answers,
                        'submitted_at' => $r->submitted_at?->toIso8601String(),
                        'created_at' => $r->created_at?->toIso8601String(),
                        'source' => $r->source,
                    ];
                });

            return [
                'survey' => [
                    'id' => $survey->id,
                    'title' => $survey->title,
                    'description' => $survey->description,
                    'type' => $survey->type,
                    'barangay_name' => $survey->barangay?->name,
                    'include_barangay' => (bool) $survey->include_barangay,
                    'published_at' => $survey->published_at?->toIso8601String(),
                    'created_at' => $survey->created_at?->toIso8601String(),
                    'responses_count' => $survey->responses_count,
                    'questions' => $survey->questions()->orderBy('order')->get(['id','question_text','type','code','data_scope'])->map(fn($q) => [
                        'id' => $q->id,
                        'question_text' => $q->question_text,
                        'type' => $q->type,
                        'code' => $q->code,
                        'data_scope' => $q->data_scope,
                    ]),
                ],
                'responses' => $responses,
                'total_responses' => $survey->responses()->count(),
            ];
        });

        return response()->json([
            'grouped' => $grouped,
            'total_surveys' => $surveys->count(),
            'total_responses' => \App\Models\SurveyResponse::count(),
        ]);
    }

    public function surveyResponses(Request $request, Survey $survey): JsonResponse
    {
        abort_unless($survey->status === \App\Models\Survey::STATUS_PUBLISHED, 404);

        $perPage = min((int) $request->input('per_page', 20), 100);
        $responses = \App\Models\SurveyResponse::where('survey_id', $survey->id)
            ->with(['household.members', 'barangay:id,name', 'answers.question'])
            ->orderByDesc('submitted_at')
            ->paginate($perPage);

        return response()->json([
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'type' => $survey->type,
            ],
            'responses' => $responses->through(function (\App\Models\SurveyResponse $r) {
                return [
                    'id' => $r->id,
                    'uuid' => $r->uuid,
                    'barangay' => $r->barangay?->name,
                    'purok' => $r->purok,
                    'household' => $r->household ? [
                        'head_name' => $r->household->head_name,
                        'contact_no' => $r->household->contact_no,
                        'members' => $r->household->members->map(fn($m) => [
                            'name' => $m->name,
                            'age' => $m->age,
                            'sex' => $m->sex,
                        ]),
                    ] : null,
                    'answers' => $r->answers->map(fn($a) => [
                        'question_text' => $a->question?->question_text ?? "Q{$a->survey_question_id}",
                        'answer' => $a->answer,
                    ]),
                    'submitted_at' => $r->submitted_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Batch synchronization endpoint — the heart of the offline-first queue.
     */
    public function sync(Request $request, SyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'device_info' => ['nullable', 'string', 'max:255'],
            'responses' => ['required', 'array'],
            'responses.*.uuid' => ['required', 'uuid'],
            'responses.*.survey_id' => ['required', 'integer'], // validated per record in SyncService
            'responses.*.barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'responses.*.purok' => ['nullable', 'string', 'max:50'],
            'responses.*.household' => ['nullable', 'array'],
            'responses.*.household.head_name' => ['nullable', 'string', 'max:255'],
            'responses.*.household.purok' => ['nullable', 'string', 'max:50'],
            'responses.*.household.address' => ['nullable', 'string', 'max:255'],
            'responses.*.household.contact_no' => ['nullable', 'string', 'max:20'],
            'responses.*.household.live_in_status' => ['nullable', 'string', 'in:Yes,No'],
            'responses.*.household.live_in_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'responses.*.household.live_in_reason' => ['nullable', 'string', 'max:500'],
            'responses.*.household_members' => ['nullable', 'array'],
            'responses.*.household_members.*.name' => ['nullable', 'string', 'max:255'],
            'responses.*.household_members.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'responses.*.household_members.*.sex' => ['nullable', 'string', 'in:Male,Female,Others'],
            'responses.*.household_members.*.civil_status' => ['nullable', 'string', 'max:30'],
            'responses.*.household_members.*.relationship' => ['nullable', 'string', 'max:50'],
            'responses.*.household_members.*.is_head' => ['nullable', 'boolean'],
            'responses.*.household_members.*.is_pwd' => ['nullable'],
            'responses.*.household_members.*.is_mentally_challenged' => ['nullable'],
            'responses.*.household_members.*.is_osy' => ['nullable'],
            'responses.*.household_members.*.osy_last_grade' => ['nullable', 'string', 'max:100'],
            'responses.*.household_members.*.bedridden_status' => ['nullable', 'string', 'max:50'],
            'responses.*.household_members.*.is_pregnant' => ['nullable'],
            'responses.*.household_members.*.lcr_registered' => ['nullable', 'string', 'in:Yes,No'],
            'responses.*.household_members.*.lcr_reason' => ['nullable', 'string', 'max:500'],
            'responses.*.household_members.*.katungdanan_status' => ['nullable', 'string', 'in:Yes,No'],
            'responses.*.household_members.*.katungdanan_position' => ['nullable', 'string', 'max:255'],
            'responses.*.respondent_data' => ['nullable', 'array'],
            'responses.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'responses.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'responses.*.submitted_at' => ['nullable', 'date'],
            'responses.*.answers' => ['present', 'array'], // may be empty; validated per record
            'responses.*.member_answers' => ['nullable', 'array'],
            'responses.*.member_answers.*' => ['array'],
        ]);

        return response()->json(
            $sync->sync(
                $validated['responses'],
                $request->user()->id,
                $validated['device_info'] ?? null,
            ),
        );
    }
}
