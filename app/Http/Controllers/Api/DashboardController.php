<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\ExtensionActivity;
use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function statistics(): JsonResponse
    {
        $user = auth()->user();
        $activityQuery = ExtensionActivity::query()
            ->when($user && $user->isCoordinator() && $user->program_id, fn ($q) => $q->where('program_id', $user->program_id));

        return response()->json([
            'total_barangays' => Barangay::where('name', '!=', 'Tangub City')->count(),
            'total_extension_activities' => (clone $activityQuery)->count(),
            'active_activities' => (clone $activityQuery)->where('status', ExtensionActivity::STATUS_ONGOING)->count(),
            'completed_activities' => (clone $activityQuery)->where('status', ExtensionActivity::STATUS_COMPLETED)->count(),
        ]);
    }

    public function activities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $activities = ExtensionActivity::query()
            ->with(['program:id,name', 'barangay:id,name'])
            ->when($user && $user->isCoordinator() && $user->program_id, fn ($q) => $q->where('program_id', $user->program_id))
            ->when($validated['program_id'] ?? null, fn ($q, $v) => $q->where('program_id', $v))
            ->when($validated['barangay_id'] ?? null, fn ($q, $v) => $q->where('barangay_id', $v))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->whereDate('start_date', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->whereDate('start_date', '<=', $v))
            ->get();

        $byProgram = $activities->groupBy('program.name')->map->count();
        $overTime = $activities
            ->groupBy(fn ($a) => $a->start_date?->format('Y-m'))
            ->sortKeys()
            ->map->count();
        $participants = [
            'faculty' => (int) $activities->sum('faculty_participants'),
            'students' => (int) $activities->sum('student_participants'),
            'beneficiaries' => (int) $activities->sum('beneficiaries'),
        ];
        $coverage = $activities->groupBy('barangay.name')->map->count();

        return response()->json([
            'by_program' => $byProgram,
            'over_time' => $overTime,
            'participants' => $participants,
            'coverage' => $coverage,
        ]);
    }

    public function surveys(): JsonResponse
    {
        return response()->json([
            'surveys' => Survey::where('status', Survey::STATUS_PUBLISHED)
                ->withCount('responses')
                ->get(['id', 'title']),
        ]);
    }
}
