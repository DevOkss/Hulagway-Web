<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSurveyRequest;
use App\Models\Barangay;
use App\Models\Survey;
use App\Services\ReportService;
use App\Services\SurveyResponseService;
use App\Services\SurveyService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SurveyController extends Controller
{
    public function __construct(
        private readonly SurveyService $surveys,
        private readonly SurveyResponseService $responses,
        private readonly ReportService $reports,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Surveys/Index', [
            'surveys' => Survey::where('status', '!=', Survey::STATUS_ARCHIVED)
                ->withCount('responses')
                ->with(['barangay:id,name', 'creator:id,name'])
                ->latest()
                ->get(),
            'archivedCount' => Survey::where('status', Survey::STATUS_ARCHIVED)->count(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Surveys/Create', [
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreSurveyRequest $request): RedirectResponse
    {
        $survey = $this->surveys->create($request->validated(), $request->user()->id);

        return redirect()
            ->route('surveys.show', $survey)
            ->with('success', 'Survey created.');
    }

    public function show(Survey $survey): Response
    {
        $householdStats = null;
        if (($survey->type ?? 'generic') === Survey::TYPE_HOUSEHOLD) {
            $stats = $this->reports->hulagwaySummaryForSurvey($survey);
            $householdIds = $survey->responses()->pluck('household_id')->filter();
            $stats['total_households'] = \App\Models\Household::whereIn('id', $householdIds)->count();
            $stats['total_members'] = \App\Models\HouseholdMember::whereIn('household_id', $householdIds)->count();
            $stats['seniors_60'] = \App\Models\HouseholdMember::whereIn('household_id', $householdIds)->where(function($q){ $q->where('is_senior', true)->orWhere('age','>=',60); })->count();
            // Government service satisfaction average (likert 1-5)
            $govQuestion = $survey->questions()->where('code', 'gov_satisfaction')->first();
            if ($govQuestion) {
                $vals = \Illuminate\Support\Facades\DB::table('survey_answers')
                    ->where('survey_question_id', $govQuestion->id)
                    ->pluck('answer')
                    ->map(fn($v) => (float) $v)
                    ->filter(fn($v) => $v >= 1 && $v <= 5);
                $stats['gov_satisfaction_avg'] = $vals->isNotEmpty() ? round($vals->avg(), 2) : null;
                $stats['gov_satisfaction_count'] = $vals->count();
            }
            $householdStats = $stats;
        }

        return Inertia::render('Surveys/Show', [
            'survey' => $survey->load(['questions.options', 'barangay:id,name', 'creator:id,name']),
            'summary' => $this->responses->summarizeByQuestion($survey),
            'totalResponses' => $survey->responses()->count(),
            'householdStats' => $householdStats,
            'barangays' => \App\Models\Barangay::orderBy('name')->get(['id','name']),
        ]);
    }

    public function aggregationDetail(Survey $survey, string $metric, \Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(($survey->type ?? 'generic') === Survey::TYPE_HOUSEHOLD, 404);

        $request->validate([
            'barangay_ids' => ['nullable','array'],
            'barangay_ids.*' => ['integer','exists:barangays,id'],
            'barangay_id' => ['nullable','integer','exists:barangays,id'],
            'search' => ['nullable','string','max:255'],
        ]);

        $barangayIds = $request->input('barangay_ids');
        if (!$barangayIds && $request->input('barangay_id')) {
            $barangayIds = [$request->input('barangay_id')];
        }
        // Support comma-separated string as well
        if (is_string($barangayIds)) {
            $barangayIds = array_filter(explode(',', $barangayIds));
        }
        $barangayIds = $barangayIds ? array_map('intval', (array) $barangayIds) : null;
        $search = trim((string) $request->input('search', ''));

        $householdIds = $survey->responses()->pluck('household_id')->filter()->values();
        if ($householdIds->isEmpty()) {
            return response()->json(['households' => [], 'members' => [], 'total' => 0]);
        }

        $isHouseholdMetric = $metric === 'live_in' || $metric === 'total_households';

        if ($isHouseholdMetric) {
            $q = \App\Models\Household::whereIn('id', $householdIds)
                ->with(['barangay:id,name', 'members'])
                ->when($barangayIds, fn($qq) => $qq->whereIn('barangay_id', $barangayIds))
                ->when($search, fn($qq) => $qq->where(fn($w) => $w->where('head_name','like',"%{$search}%")->orWhere('purok','like',"%{$search}%")))
                ->orderBy('barangay_id')->orderBy('purok')->orderBy('head_name');

            if ($metric === 'live_in') {
                $q->where('live_in_status', 'Yes');
            }

            $households = $q->get()->map(fn($h) => [
                'id' => $h->id,
                'head_name' => $h->head_name,
                'barangay' => $h->barangay?->name ?? '-',
                'barangay_id' => $h->barangay_id,
                'purok' => $h->purok,
                'contact_no' => $h->contact_no,
                'live_in_status' => $h->live_in_status,
                'live_in_years' => $h->live_in_years,
                'live_in_reason' => $h->live_in_reason,
                'members_count' => $h->members->count(),
                'members' => $h->members->map(fn($m) => ['name'=>$m->name,'age'=>$m->age,'sex'=>$m->sex]),
            ]);

            return response()->json(['households' => $households, 'members' => [], 'total' => $households->count()]);
        }

        // Person-level metrics
        $memberQuery = \App\Models\HouseholdMember::whereIn('household_id', $householdIds)
            ->with(['household:id,barangay_id,purok,head_name', 'household.barangay:id,name'])
            ->when($barangayIds, fn($qq) => $qq->whereHas('household', fn($hq) => $hq->whereIn('barangay_id', $barangayIds)))
            ->when($search, fn($qq) => $qq->where(fn($w) => $w->where('name','like',"%{$search}%")->orWhere('relationship','like',"%{$search}%")))
            ->orderBy('name');

        switch ($metric) {
            case 'pwd':
            case 'pwd_cat2':
                $memberQuery = \App\Models\HouseholdMember::whereIn('household_id', $householdIds)
                    ->where('is_pwd','!=','No')->where('is_pwd','!=','')->whereNotNull('is_pwd')
                    ->with(['household:id,barangay_id,purok,head_name','household.barangay:id,name'])
                    ->when($barangayIds, fn($qq) => $qq->whereHas('household', fn($hq) => $hq->whereIn('barangay_id', $barangayIds)))
                    ->when($search, fn($qq) => $qq->where(fn($w) => $w->where('name','like',"%{$search}%")))
                    ->orderBy('name');
                break;
            case 'senior_90':
            case 'seniors_90':
                $memberQuery->where('age','>=',90);
                break;
            case 'senior_60':
            case 'seniors_60':
                $memberQuery->where(function($q){ $q->where('is_senior', true)->orWhere('age','>=',60); });
                break;
            case 'osy':
                $memberQuery->where('is_osy', true);
                break;
            case 'bedridden':
            case 'bedridden_cat2':
                $memberQuery->whereIn('bedridden_status', ['Yes - Mabakod pa','Yes - Di na kabakod']);
                break;
            case 'pregnant':
                $memberQuery->where('is_pregnant', true);
                break;
            case 'mentally':
            case 'mentally_cat2':
                $memberQuery->whereIn('is_mentally_challenged', ['Yes - dili problema sa katilingban','Yes - hasol sa katilingban']);
                // fallback for old boolean string
                break;
            default:
                return response()->json(['households'=>[],'members'=>[],'total'=>0, 'error'=>'Unknown metric'], 400);
        }

        $members = $memberQuery->get()->map(fn($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'age' => $m->age,
            'sex' => $m->sex,
            'civil_status' => $m->civil_status,
            'relationship' => $m->relationship,
            'is_head' => $m->is_head,
            'barangay' => $m->household?->barangay?->name ?? '-',
            'barangay_id' => $m->household?->barangay_id,
            'purok' => $m->household?->purok,
            'household_head' => $m->household?->head_name,
            'household_id' => $m->household_id,
            'is_pwd' => $m->is_pwd,
            'is_mentally_challenged' => $m->is_mentally_challenged,
            'is_osy' => $m->is_osy,
            'bedridden_status' => $m->bedridden_status,
            'is_pregnant' => $m->is_pregnant,
            'lcr_registered' => $m->lcr_registered,
            'katungdanan_status' => $m->katungdanan_status,
        ]);

        return response()->json(['households'=>[], 'members' => $members, 'total' => $members->count()]);
    }

    public function edit(Survey $survey): Response
    {
        return Inertia::render('Surveys/Edit', [
            'survey' => $survey->load(['questions.options']),
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(StoreSurveyRequest $request, Survey $survey): RedirectResponse
    {
        $this->surveys->update($survey, $request->validated());

        return redirect()
            ->route('surveys.show', $survey)
            ->with('success', 'Survey updated.');
    }

    public function publish(Survey $survey): RedirectResponse
    {
        $this->surveys->publish($survey);

        return back()->with('success', 'Survey published. Public link is now active.');
    }

    public function deactivate(Survey $survey): RedirectResponse
    {
        $this->surveys->deactivate($survey);

        return back()->with('success', 'Survey deactivated.');
    }

    public function archive(Survey $survey): RedirectResponse
    {
        abort_if($survey->status === Survey::STATUS_PUBLISHED, 403, 'Deactivate the survey before archiving.');

        $this->surveys->archive($survey);

        return back()->with('success', 'Survey archived.');
    }

    public function restore(Survey $survey): RedirectResponse
    {
        $this->surveys->restore($survey);

        return back()->with('success', 'Survey restored.');
    }

    public function archived(): Response
    {
        return Inertia::render('Surveys/Archived', [
            'surveys' => Survey::where('status', Survey::STATUS_ARCHIVED)
                ->withCount('responses')
                ->with(['barangay:id,name', 'creator:id,name'])
                ->latest()
                ->get(),
        ]);
    }

    public function destroy(Survey $survey): RedirectResponse
    {
        abort_if($survey->status === Survey::STATUS_PUBLISHED, 403, 'Deactivate the survey first.');

        $survey->delete();

        return redirect()->route('surveys.index')->with('success', 'Survey deleted.');
    }
}
