<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionActivityRequest;
use App\Exports\ActivitiesExport;
use App\Models\Barangay;
use App\Models\ExtensionActivity;
use App\Models\Program;
use App\Models\Sdg;
use App\Services\ExtensionActivityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\In;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExtensionActivityController extends Controller
{
    public function __construct(private readonly ExtensionActivityService $activities) {}

    private function availableYears(): array
    {
        return ExtensionActivity::all(['start_date','end_date'])
            ->flatMap(fn ($a) => [$a->start_date?->format('Y'), $a->end_date?->format('Y')])
            ->filter()->unique()->map(fn ($y) => (int) $y)->sortDesc()->values()->all() ?: [(int) date('Y')];
    }

    public function index(Request $request): Response
    {
        $this->refreshStaleForScopedQuery();
        $year = $request->validate(['year' => ['nullable','integer','min:2020','max:2035']])['year'] ?? null;

        $query = $this->scopedQuery()->with(['program:id,name,institute_id', 'barangay:id,name', 'collaborators:id,name', 'sdgs', 'days', 'dailyTasks']);
        if ($year) {
            $query->where(function ($qq) use ($year) {
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function ($qq2) use ($year) { $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year); });
            });
        }

        return Inertia::render('Extensions/Index', [
            'activities' => $query->latest('start_date')->get(),
            'programs' => Program::orderBy('name')->get(['id', 'name']),
            'years' => $this->availableYears(),
            'filters' => ['year' => $year ? (string) $year : null],
        ]);
    }

    public function pending(Request $request): Response
    {
        $this->refreshStaleForScopedQuery();
        $year = $request->validate(['year' => ['nullable','integer','min:2020','max:2035']])['year'] ?? null;

        $query = $this->scopedQuery()
            ->whereIn('status', [ExtensionActivity::STATUS_PLANNED, ExtensionActivity::STATUS_ONGOING])
            ->with(['program:id,name,institute_id', 'barangay:id,name', 'collaborators:id,name', 'sdgs', 'days', 'dailyTasks']);
        if ($year) {
            $query->where(function ($qq) use ($year) {
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function ($qq2) use ($year) { $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year); });
            });
        }

        return Inertia::render('Extensions/Pending', [
            'activities' => $query->latest('start_date')->get(),
            'programs' => Program::orderBy('name')->get(['id', 'name']),
            'years' => $this->availableYears(),
            'filters' => ['year' => $year ? (string) $year : null],
        ]);
    }

    public function completed(Request $request): Response
    {
        $this->refreshStaleForScopedQuery();
        $year = $request->validate(['year' => ['nullable','integer','min:2020','max:2035']])['year'] ?? null;

        $query = $this->scopedQuery()
            ->where('status', ExtensionActivity::STATUS_COMPLETED)
            ->with(['program:id,name,institute_id', 'barangay:id,name', 'collaborators:id,name', 'sdgs', 'days', 'dailyTasks']);
        if ($year) {
            $query->where(function ($qq) use ($year) {
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function ($qq2) use ($year) { $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year); });
            });
        }

        return Inertia::render('Extensions/Completed', [
            'activities' => $query->latest('start_date')->get(),
            'programs' => Program::orderBy('name')->get(['id', 'name']),
            'years' => $this->availableYears(),
            'filters' => ['year' => $year ? (string) $year : null],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Extensions/Create', [
            'programs' => $this->scopedPrograms(),
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
            'sdgs' => Sdg::orderBy('number')->get(['id', 'number', 'code', 'title', 'short_title', 'color', 'icon_url']),
        ]);
    }

    public function store(ExtensionActivityRequest $request): RedirectResponse
    {
        $documentMeta = [
            'activity_date' => $request->input('document_activity_date'),
            'progress' => $request->input('document_progress'),
            'caption' => $request->input('document_caption'),
        ];

        $this->activities->create(
            $request->safe()->except(['documents', 'document_activity_date', 'document_progress', 'document_caption', 'days', 'day_count', 'collaborator_program_ids']),
            $request->user()->id,
            array_filter((array) $request->file('documents', [])),
            $documentMeta,
            array_values((array) $request->input('days', [])),
        );

        return redirect()->route('extensions.index')->with('success', 'Activity created.');
    }

    public function show(ExtensionActivity $extensionActivity): Response
    {
        $this->authorizeView($extensionActivity);
        $user = auth()->user();

        // Keep status/progress automatic and up-to-date even if days have advanced since last write
        $this->activities->refreshIfStale($extensionActivity->load('days'));

        return Inertia::render('Extensions/Show', [
            'activity' => $extensionActivity->load([
                'program:id,name,institute_id',
                'barangay:id,name',
                'creator:id,name',
                'documents',
                'dailyTasks',
                'days',
                'collaborators:id,name',
                'sdgs',
            ]),
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
            // Programs available for collaboration (all except the lead program) — grouped by institute name
            'collaborationPrograms' => Program::with('institute:id,name')
                ->where('id', '!=', $extensionActivity->program_id)
                ->orderBy('name')
                ->get(['id', 'name', 'institute_id'])
                ->groupBy(fn ($p) => $p->institute?->name ?? 'Other'),
            'canManage' => $extensionActivity->isManagedBy($user),
        ]);
    }

    public function edit(ExtensionActivity $extensionActivity): Response
    {
        $this->authorizeManage($extensionActivity);

        return Inertia::render('Extensions/Edit', [
            'activity' => $extensionActivity->load(['documents', 'days', 'sdgs']),
            'programs' => $this->scopedPrograms(),
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
            'sdgs' => Sdg::orderBy('number')->get(['id', 'number', 'code', 'title', 'short_title', 'color', 'icon_url']),
        ]);
    }

    public function update(ExtensionActivityRequest $request, ExtensionActivity $extensionActivity): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);

        $documentMeta = [
            'activity_date' => $request->input('document_activity_date'),
            'progress' => $request->input('document_progress'),
            'caption' => $request->input('document_caption'),
        ];

        // Only sync days if the request explicitly included them (daily upload has no days)
        $daysInput = $request->has('days') ? $request->input('days') : ($request->has('existing_days') ? $request->input('existing_days') : null);
        $days = $daysInput !== null ? array_values((array) $daysInput) : null;

        $this->activities->update(
            $extensionActivity,
            $request->safe()->except(['documents', 'document_activity_date', 'document_progress', 'document_caption', 'days', 'day_count', 'collaborator_program_ids']),
            array_filter((array) $request->file('documents', [])),
            $documentMeta,
            $days,
        );

        return redirect()
            ->route('extensions.show', $extensionActivity)
            ->with('success', 'Activity updated.');
    }

    /**
     * Lead coordinator manages collaborating programs.
     */
    public function updateCollaborators(Request $request, ExtensionActivity $extensionActivity): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);

        $validated = $request->validate([
            'collaborator_program_ids' => ['nullable', 'array'],
            'collaborator_program_ids.*' => ['integer', 'exists:programs,id'],
        ]);

        // Never allow the lead program to be its own collaborator
        $ids = collect($validated['collaborator_program_ids'] ?? [])
            ->reject(fn ($id) => (int) $id === (int) $extensionActivity->program_id)
            ->unique()
            ->values();

        $extensionActivity->collaborators()->sync($ids);

        return back()->with('success', 'Collaborators updated.');
    }

    public function destroy(ExtensionActivity $extensionActivity): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);
        abort_if($extensionActivity->status === ExtensionActivity::STATUS_COMPLETED, 422, 'Cannot delete a completed activity.');
        $extensionActivity->delete();

        return redirect()->route('extensions.index')->with('success', 'Activity deleted.');
    }

    public function downloadDocument(ExtensionActivity $extensionActivity, int $document): StreamedResponse
    {
        $this->authorizeView($extensionActivity);

        $doc = $extensionActivity->documents()->findOrFail($document);
        abort_unless(Storage::disk('public')->exists($doc->file_path), 404, 'File not found.');

        return Storage::disk('public')->download($doc->file_path, $doc->original_name);
    }

    public function destroyDocument(ExtensionActivity $extensionActivity, int $document): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);
        $this->activities->deleteDocument($extensionActivity, $document);

        return back()->with('success', 'Document removed.');
    }

    public function storeDailyTask(Request $request, ExtensionActivity $extensionActivity): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);

        $validated = $this->validateDailyTask($request, $extensionActivity);

        $extensionActivity->dailyTasks()->create($validated);

        return back()->with('success', 'Daily task added.');
    }

    public function updateDailyTask(Request $request, ExtensionActivity $extensionActivity, int $task): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);

        $dailyTask = $extensionActivity->dailyTasks()->findOrFail($task);

        $validated = $this->validateDailyTask($request, $extensionActivity);

        $dailyTask->update($validated);

        return back()->with('success', 'Daily task updated.');
    }

    public function destroyDailyTask(ExtensionActivity $extensionActivity, int $task): RedirectResponse
    {
        $this->authorizeManage($extensionActivity);

        $extensionActivity->dailyTasks()->findOrFail($task)->delete();

        return back()->with('success', 'Daily task removed.');
    }

    public function exportBulk(Request $request, string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
    {
        abort_unless(in_array($format, ['pdf','xlsx'], true), 400);
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'in:planned,ongoing,completed,cancelled'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2035'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
        ]);

        // Normalize status: support ?status=completed or ?statuses[]=planned&statuses[]=ongoing
        $rawStatus = $request->input('status') ?? $validated['statuses'] ?? null;
        if (isset($validated['status']) && is_string($validated['status'])) $rawStatus = $validated['status'];
        $statuses = null;
        if (is_array($rawStatus)) {
            $statuses = array_values(array_filter($rawStatus, fn($v) => in_array($v, ['planned','ongoing','completed','cancelled'], true)));
            if (empty($statuses)) $statuses = null;
        } elseif (is_string($rawStatus) && $rawStatus !== '') {
            $statuses = str_contains($rawStatus, ',') ? explode(',', $rawStatus) : [$rawStatus];
            $statuses = array_values(array_filter($statuses, fn($v) => in_array($v, ['planned','ongoing','completed','cancelled'], true)));
            if (empty($statuses)) $statuses = null;
        }

        $year = $validated['year'] ?? null;
        $programId = $validated['program_id'] ?? null;
        $barangayId = $validated['barangay_id'] ?? null;

        $query = $this->scopedQuery()->with(['program.institute:id,name', 'barangay:id,name', 'sdgs', 'collaborators:id,name', 'days', 'dailyTasks', 'documents']);

        if ($statuses) {
            $query->whereIn('status', $statuses);
        } else {
            $query->whereIn('status', [ExtensionActivity::STATUS_PLANNED, ExtensionActivity::STATUS_ONGOING, ExtensionActivity::STATUS_COMPLETED]);
        }
        if ($year) {
            $query->where(function ($qq) use ($year) {
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function ($qq2) use ($year) { $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year); });
            });
        }
        if ($programId) $query->where('program_id', $programId);
        if ($barangayId) $query->where('barangay_id', $barangayId);

        $activities = $query->orderBy('start_date')->get();
        // Ensure fresh status/progress
        $activities->each(fn ($a) => $this->activities->refreshIfStale($a->load('days')));

        if ($format === 'xlsx') {
            return Excel::download(new ActivitiesExport($activities), 'extension-activities-'.($year ?? 'all').'.xlsx');
        }
        return Pdf::loadView('reports.activities', ['activities' => $activities, 'generatedAt' => now()->format('F d, Y h:i A')])->setPaper('a4', 'landscape')->download('extension-activities-'.($year ?? 'all').'.pdf');
    }

    public function exportSingle(ExtensionActivity $extensionActivity, string $format): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
    {
        $this->authorizeView($extensionActivity);
        abort_unless(in_array($format, ['pdf','xlsx'], true), 400);
        $this->activities->refreshIfStale($extensionActivity->load('days'));
        $activity = $extensionActivity->load(['program.institute:id,name', 'barangay:id,name', 'sdgs', 'collaborators:id,name', 'days', 'dailyTasks', 'documents', 'creator:id,name']);
        $activities = collect([$activity]);

        if ($format === 'xlsx') {
            return Excel::download(new ActivitiesExport($activities), 'extension-activity-'.$activity->id.'.xlsx');
        }
        return Pdf::loadView('reports.activities', ['activities' => $activities, 'generatedAt' => now()->format('F d, Y h:i A')])->setPaper('a4', 'landscape')->download('extension-activity-'.$activity->id.'.pdf');
    }

    /**
     * Daily tasks must fall on one of the fragmented activity days.
     *
     * @return array<string, mixed>
     */
    private function validateDailyTask(Request $request, ExtensionActivity $activity): array
    {
        $validDates = $activity->days()->pluck('activity_date')->map(fn ($d) => $d->toDateString())->all();

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'scheduled_date' => ['required', 'date', new In($validDates)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * View access: CAES Officer, lead program coordinator, or collaborator program coordinator.
     */
    private function authorizeView(ExtensionActivity $activity): void
    {
        abort_unless($activity->isViewableBy(auth()->user()), 403, 'You do not have access to this activity.');
    }

    /**
     * Manage access: only the LEAD program coordinator.
     */
    private function authorizeManage(ExtensionActivity $activity): void
    {
        abort_unless($activity->isManagedBy(auth()->user()), 403, 'Only the lead program coordinator can manage this activity.');
    }

    /**
     * Coordinators see lead + collaborated activities; officers see everything.
     */
    private function scopedQuery(): Builder
    {
        $user = auth()->user();

        if ($user->isCoordinator() && $user->program_id) {
            return ExtensionActivity::query()->where(function (Builder $q) use ($user) {
                $q->where('program_id', $user->program_id)
                    ->orWhereHas('collaborators', fn ($qq) => $qq->where('programs.id', $user->program_id));
            });
        }

        return ExtensionActivity::query();
    }

    /**
     * Programs selectable when creating/editing — a coordinator may only
     * file activities under their own program; officers may pick any.
     */
    private function scopedPrograms(): Collection
    {
        $user = auth()->user();

        if ($user->isCoordinator() && $user->program_id) {
            return Program::where('id', $user->program_id)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return Program::orderBy('name')->get(['id', 'name']);
    }

    /**
     * Ensure status/progress are fresh for the current user's activities.
     * Prevents stale `status` from leaking into filtered pages (e.g. Completed).
     */
    private function refreshStaleForScopedQuery(): void
    {
        $this->scopedQuery()->with('days')->get()->each(fn (ExtensionActivity $activity) => $this->activities->refreshIfStale($activity));
    }
}
