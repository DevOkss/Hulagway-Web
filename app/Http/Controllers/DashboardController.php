<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\ExtensionActivity;
use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse|SymfonyResponse
    {
        $user = $request->user();
        // Mayor only views map (household + extensions identical to Community Map)
        if ($user && $user->isMayor()) {
            return redirect()->route('map.index');
        }

        $year = $this->yearFilter($request);
        $activities = ExtensionActivity::query()
            ->with('program:id,name')
            ->when($user && $user->isCoordinator() && $user->program_id, fn ($q) => $q->where('program_id', $user->program_id))
            ->when($year, fn ($q) => $q->where(function ($qq) use ($year) {
                $qq->whereYear('start_date', $year)
                    ->orWhereYear('end_date', $year)
                    ->orWhere(function ($qq2) use ($year) {
                        $qq2->whereYear('start_date', '<=', $year)->whereYear('end_date', '>=', $year);
                    });
            }))
            ->get();

        // Hulagway face — city-wide totals for the 8 core metrics (Category 2 where applicable)
        // Kept for fallback when no survey selected; primary aggregation is now survey-based via /api/map/aggregation
        $hulagway = $this->hulagwayTotals($year);

        // Household surveys for survey-aware hulagway aggregation (same as Map) — latest first
        // Filtered by selected year (survey created year) when year is set
        $surveys = Survey::where('status', Survey::STATUS_PUBLISHED)
            ->where('type', Survey::TYPE_HOUSEHOLD)
            ->when($year, fn ($q) => $q->whereYear('created_at', $year))
            ->with(['questions:id,survey_id,question_text,type,code,data_scope,map_enabled', 'questions.options:id,survey_question_id,label'])
            ->orderByDesc('id')
            ->get(['id', 'title', 'created_at']);

        // Available years for filter (activities + surveys) – PHP extracted for sqlite compatibility
        $years = collect()
            ->merge(ExtensionActivity::all(['start_date','end_date'])->flatMap(fn ($a) => [$a->start_date?->format('Y'), $a->end_date?->format('Y')]))
            ->merge(Survey::where('type', Survey::TYPE_HOUSEHOLD)->get(['created_at'])->map(fn ($s) => $s->created_at?->format('Y')))
            ->filter()->unique()->map(fn ($y) => (int) $y)->sortDesc()->values()->all();
        if (empty($years)) $years = [(int) date('Y')];

        return Inertia::render('Dashboard', [
            'stats' => [
                // 55 barangays of Tangub City — exclude the synthetic "Tangub City" row (id 1, null boundary)
                'total_barangays' => Barangay::where('name', '!=', 'Tangub City')->count(),
                'total_activities' => $activities->count(),
                'active_activities' => $activities->where('status', ExtensionActivity::STATUS_ONGOING)->count(),
                'completed_activities' => $activities->where('status', ExtensionActivity::STATUS_COMPLETED)->count(),
            ],
            'hulagway' => $hulagway,
            'surveys' => $surveys,
            'years' => $years,
            'charts' => [
                'byProgram' => $activities->groupBy(fn ($a) => $a->program?->name ?? 'Unassigned')
                    ->map->count(),
                'overTime' => $activities
                    ->groupBy(fn ($a) => $a->start_date?->format('Y-m'))
                    ->sortKeys()
                    ->map->count(),
                'participants' => [
                    'faculty' => (int) $activities->sum('faculty_participants'),
                    'students' => (int) $activities->sum('student_participants'),
                    'beneficiaries' => (int) $activities->sum('beneficiaries'),
                ],
            ],
            'filters' => ['year' => $year ? (string) $year : null],
        ]);
    }

    private function hulagwayTotals(?int $year): array
    {
        $memberCount = function (string $field, $value) use ($year) {
            $q = \App\Models\HouseholdMember::query()
                ->when($year, fn($qq) => $qq->whereYear('created_at', $year));
            if (is_array($value)) $q->whereIn($field, $value); else $q->where($field, $value);
            return $q->count();
        };
        $fallback = function (string $code, string $like) use ($year) {
            $q = \Illuminate\Support\Facades\DB::table('survey_answers')
                ->join('survey_responses', 'survey_responses.id', '=', 'survey_answers.survey_response_id')
                ->join('survey_questions', 'survey_questions.id', '=', 'survey_answers.survey_question_id')
                ->where('survey_questions.code', $code)->where('survey_answers.answer','like',$like)
                ->when($year, fn($qq) => $qq->whereYear('survey_responses.submitted_at', $year));
            if ($code === 'poor_cat2') return $q->distinct()->count('survey_responses.household_id');
            return $q->whereNotNull('survey_answers.household_member_id')->distinct()->count('survey_answers.household_member_id');
        };

        $seniors = \App\Models\HouseholdMember::where('age', '>=', 90)->when($year, fn($q) => $q->whereYear('created_at', $year))->count();
        if ($seniors === 0) $seniors = $fallback('seniors_90','Yes');

        $pwd = $memberCount('is_pwd', ['Yes - Makalakaw pa','Yes - Di na ka lakaw']); if ($pwd===0) $pwd=$fallback('pwd_cat2','Yes');
        $bedridden = $memberCount('bedridden_status', ['Yes - Mabakod pa','Yes - Di na kabakod']); if ($bedridden===0) $bedridden=$fallback('bedridden_cat2','Yes');
        $mentally = $memberCount('is_mentally_challenged', ['Yes - dili problema sa katilingban','Yes - hasol sa katilingban']); if ($mentally===0) $mentally=$fallback('mentally_cat2','Yes');
        $osy = $memberCount('is_osy', 1); if ($osy===0) $osy=$fallback('osy','Yes');
        $pregnant = $memberCount('is_pregnant', 1); if ($pregnant===0) $pregnant=$fallback('pregnant','Yes');
        $liveIn = \App\Models\Household::where('live_in_status','Yes')->when($year, fn($q)=>$q->whereYear('created_at', $year))->count();
        if ($liveIn===0) $liveIn=$fallback('live_in','Yes');

        return [
            'seniors_90' => $seniors,
            'bedridden_cat2' => $bedridden,
            'mentally_cat2' => $mentally,
            'pwd_cat2' => $pwd,
            'osy' => $osy,
            'live_in' => $liveIn,
            'poor_cat2' => $fallback('poor_cat2', 'Category 2%'),
            'pregnant' => $pregnant,
        ];
    }

    private function yearFilter(Request $request): ?int
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2035'],
        ]);

        return $validated['year'] ?? null;
    }
}
