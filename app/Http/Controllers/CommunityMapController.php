<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use App\Services\MapService;
use Inertia\Inertia;
use Inertia\Response;

class CommunityMapController extends Controller
{
    public function __construct(private readonly MapService $map) {}

    public function index(): Response
    {
        // Distinct years for extension filter – PHP extracted for sqlite compatibility
        $years = \App\Models\ExtensionActivity::all(['start_date','end_date'])
            ->flatMap(fn ($a) => [$a->start_date?->format('Y'), $a->end_date?->format('Y')])
            ->filter()->unique()->map(fn ($y) => (int) $y)->sort()->values()->all();
        if (empty($years)) $years = [date('Y')];

        return Inertia::render('Map/Community', [
            'community' => $this->map->communityGeoJson(),
            'extensions' => $this->map->extensionsGeoJson(),
            'surveys' => Survey::where('status', Survey::STATUS_PUBLISHED)->where('type', Survey::TYPE_HOUSEHOLD)->with(['questions:id,survey_id,question_text,type,code,data_scope,map_enabled', 'questions.options:id,survey_question_id,label'])->get(['id', 'title', 'barangay_id', 'include_barangay']),
            'barangays' => \App\Models\Barangay::orderBy('name')
                ->where(fn ($q) => $q->whereNotNull('boundary')->orWhereNotNull('latitude')->orWhereNotNull('longitude'))
                ->get(['id', 'name']),
            'programs' => \App\Models\Program::orderBy('name')->get(['id', 'name']),
            'sdgs' => \App\Models\Sdg::orderBy('number')->get(['id', 'number', 'code', 'title', 'short_title', 'color', 'icon_url']),
            'extensionYears' => $years,
        ]);
    }
}
