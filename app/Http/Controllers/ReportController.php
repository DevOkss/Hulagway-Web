<?php

namespace App\Http\Controllers;

use App\Exports\ActivitiesExport;
use App\Exports\SurveyResponsesExport;
use App\Models\Survey;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Reports page with filters (Inertia).
     * Officer: all extension activities (planned, ongoing, completed) across all programs.
     * Coordinator: only own program + collaboration activities.
     * Year filter replaces date range; also affects export.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'status' => ['nullable', 'in:planned,ongoing,completed,cancelled'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2035'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $activities = $this->reports->activities(
            $filters['program_id'] ?? null,
            $filters['barangay_id'] ?? null,
            $filters['status'] ?? null,
            $filters['year'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );
        $activities = $this->scopeForUser($activities, $request->user());

        return inertia('Reports/Index', [
            'activities' => $activities,
            'hulagway' => $this->reports->hulagwaySummary($filters['from'] ?? null, $filters['to'] ?? null, $filters['year'] ?? null),
            'filters' => $filters,
            ...$this->reports->filterOptions(),
            'years' => $this->availableYears(),
        ]);
    }

    public function activities(Request $request, string $format)
    {
        $filters = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'status' => ['nullable', 'in:planned,ongoing,completed,cancelled'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2035'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $activities = $this->reports->activities(
            $filters['program_id'] ?? null,
            $filters['barangay_id'] ?? null,
            $filters['status'] ?? null,
            $filters['year'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );
        $activities = $this->scopeForUser($activities, $request->user());

        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 400);

        if ($format === 'xlsx') {
            return Excel::download(new ActivitiesExport($activities), 'extension-activities.xlsx');
        }

        return Pdf::loadView('reports.activities', [
            'activities' => $activities,
            'generatedAt' => now()->format('F d, Y h:i A'),
        ])->setPaper('a4', 'landscape')->download('extension-activities.pdf');
    }

    public function survey(Survey $survey, string $format)
    {
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 400);

        if ($format === 'xlsx') {
            return Excel::download(new SurveyResponsesExport($survey->load(['questions', 'responses'])), "survey-{$survey->id}-responses.xlsx");
        }

        $summary = $this->reports->surveySummary($survey);

        return Pdf::loadView('reports.survey-summary', [
            'summary' => $summary,
            'generatedAt' => now()->format('F d, Y h:i A'),
        ])->setPaper('a4', 'landscape')->download("survey-{$survey->id}-summary.pdf");
    }

    private function scopeForUser(\Illuminate\Support\Collection $activities, $user)
    {
        if ($user && $user->isOfficer()) {
            // Officer sees all (planned, ongoing, completed) – no filter
            return $activities;
        }
        if ($user && $user->isCoordinator() && $user->program_id) {
            return $activities->filter(fn ($a) => $a->isViewableBy($user))->values();
        }
        // Fallback: no activities
        return collect();
    }

    private function availableYears(): array
    {
        $years = \App\Models\ExtensionActivity::all(['start_date','end_date'])
            ->flatMap(fn ($a) => [$a->start_date?->format('Y'), $a->end_date?->format('Y')])
            ->filter()->unique()->map(fn ($y) => (int) $y)->sortDesc()->values()->all();
        return $years ?: [(int) date('Y')];
    }
}
