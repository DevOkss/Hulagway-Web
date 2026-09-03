<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function __construct(private readonly MapService $map) {}

    public function community(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'survey_id' => ['nullable', 'integer', 'exists:surveys,id'],
            'metric' => ['nullable', 'string', 'in:total_responses,households,seniors_90,bedridden_cat2,mentally_cat2,pwd_cat2,osy,live_in,poor_cat2,pregnant'],
            'question_id' => ['nullable', 'integer', 'exists:survey_questions,id'],
            'question_ids' => ['nullable', 'array'],
            'question_ids.*' => ['integer', 'exists:survey_questions,id'],
            'field_codes' => ['nullable', 'array'],
            'field_codes.*' => ['string', 'max:50'],
            'barangay_ids' => ['nullable', 'array'],
            'barangay_ids.*' => ['integer', 'exists:barangays,id'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'answer' => ['nullable', 'string', 'max:255'],
            'household_field' => ['nullable', 'string', 'in:age,sex'],
            'bucket' => ['nullable', 'string', 'in:children,adult,seniors,seniors_90'],
        ]);

        // Normalize barangay_ids: support single barangay_id as well
        $barangayIds = $validated['barangay_ids'] ?? null;
        if (!$barangayIds && isset($validated['barangay_id'])) {
            $barangayIds = [$validated['barangay_id']];
        }
        // Support field_codes as alias for multi-field, and question_ids
        $questionIds = $validated['question_ids'] ?? null;
        // Also support legacy single question_id as array
        if (!$questionIds && isset($validated['question_id'])) {
            $questionIds = [$validated['question_id']];
        }
        $fieldCodes = $validated['field_codes'] ?? null;

        return response()->json(
            $this->map->communityGeoJson(
                $validated['survey_id'] ?? null,
                $validated['metric'] ?? null,
                $validated['question_id'] ?? null,
                $validated['answer'] ?? null,
                $validated['household_field'] ?? null,
                $validated['bucket'] ?? null,
                $barangayIds,
                $questionIds,
                $fieldCodes
            ),
        );
    }

    public function aggregation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'survey_id' => ['required', 'integer', 'exists:surveys,id'],
            'question_ids' => ['nullable', 'array'],
            'question_ids.*' => ['integer', 'exists:survey_questions,id'],
            'field_codes' => ['nullable', 'array'],
            'field_codes.*' => ['string', 'max:50'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'barangay_ids' => ['nullable', 'array'],
            'barangay_ids.*' => ['integer', 'exists:barangays,id'],
        ]);

        $barangayIds = $validated['barangay_ids'] ?? null;
        if (!$barangayIds && isset($validated['barangay_id'])) {
            $barangayIds = [$validated['barangay_id']];
        }

        return response()->json(
            $this->map->aggregationDetail(
                $validated['survey_id'],
                $validated['question_ids'] ?? null,
                $validated['field_codes'] ?? null,
                $barangayIds,
            ),
        );
    }

    public function extensions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'status' => ['nullable'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'in:planned,ongoing,completed,cancelled'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2035'],
            'barangay_ids' => ['nullable', 'array'],
            'barangay_ids.*' => ['integer', 'exists:barangays,id'],
            'sdg_ids' => ['nullable', 'array'],
            'sdg_ids.*' => ['integer', 'exists:sdgs,id'],
        ]);

        // Normalize status: support ?status=ongoing, ?status[]=ongoing, ?statuses[]=ongoing
        $rawStatus = $request->input('status') ?? $validated['statuses'] ?? null;
        // Also fallback to validated status if it was array
        if (isset($validated['status']) && is_array($validated['status'])) {
            $rawStatus = $validated['status'];
        } elseif (isset($validated['status']) && is_string($validated['status'])) {
            $rawStatus = $validated['status'];
        }
        $statuses = null;
        if (is_array($rawStatus)) {
            $statuses = array_values(array_filter($rawStatus, fn($v)=> in_array($v, ['planned','ongoing','completed','cancelled'], true)));
            if (empty($statuses)) $statuses = null;
        } elseif (is_string($rawStatus) && $rawStatus !== '') {
            $statuses = in_array($rawStatus, ['planned','ongoing','completed','cancelled'], true) ? [$rawStatus] : null;
        }

        return response()->json(
            $this->map->extensionsGeoJson(
                $validated['program_id'] ?? null,
                $statuses,
                $validated['barangay_ids'] ?? null,
                $validated['sdg_ids'] ?? null,
                $validated['year'] ?? null
            ),
        );
    }
}
