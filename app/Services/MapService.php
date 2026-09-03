<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\ExtensionActivity;
use App\Models\SurveyResponse;
use Illuminate\Support\Collection;

class MapService
{
    /**
     * Barangay-level aggregated community data as a GeoJSON FeatureCollection.
     * Supports hulagway metrics + fully dynamic per-question aggregation (no code required).
     * New: household survey multi-field with barangay filter.
     *
     * @param array<int>|null $barangayIds
     * @param array<int>|null $questionIds
     * @param array<string>|null $fieldCodes
     * @return array<string, mixed>
     */
    public function communityGeoJson(?int $surveyId = null, ?string $metric = null, ?int $questionId = null, ?string $answer = null, ?string $householdField = null, ?string $bucket = null, ?array $barangayIds = null, ?array $questionIds = null, ?array $fieldCodes = null): array
    {
        // New household survey multi-field mode
        if ($surveyId && ($questionIds || $fieldCodes)) {
            return $this->householdSurveyMultiFieldGeoJson($surveyId, $questionIds, $fieldCodes, $barangayIds);
        }
        // Barangay filter for single survey (household survey with no fields = total responses choropleth filtered)
        if ($surveyId && $barangayIds) {
            // If barangay filter present without fields, still filter the base query
            if ($questionId === null && $householdField === null && !$metric) {
                return $this->householdSurveyFilteredGeoJson($surveyId, $barangayIds);
            }
        }
        // Fully dynamic: any question_id (no code needed) or household age/sex
        if ($questionId !== null || $householdField !== null) {
            return $this->genericQuestionGeoJson($surveyId, $questionId, $answer, $householdField, $bucket);
        }
        // Hulagway metrics use household_members or survey_answers (code-based)
        if ($metric && $metric !== 'total_responses' && $metric !== 'households') {
            return $this->hulagwayGeoJson($surveyId, $metric);
        }

        $query = SurveyResponse::query()
            ->selectRaw('barangay_id, COUNT(*) as aggregate')
            ->whereNotNull('barangay_id')
            ->groupBy('barangay_id');

        if ($surveyId) {
            $query->where('survey_id', $surveyId);
        }

        $counts = $query->pluck('aggregate', 'barangay_id');

        // For households metric, count distinct households per barangay
        if ($metric === 'households') {
            $counts = \App\Models\Household::query()
                ->selectRaw('barangay_id, COUNT(*) as aggregate')
                ->groupBy('barangay_id')
                ->pluck('aggregate', 'barangay_id');
        }

        // Apply barangay filter if provided (for household survey filtered view)
        $barangaysQuery = Barangay::withCount(['extensionActivities'])->withCount('households');
        // Note: barangayIds filter handled at feature level via buildFeatureCollection filtering

        return $this->buildFeatureCollection(
            Barangay::withCount(['extensionActivities'])->withCount('households')->get(),
            fn (Barangay $barangay) => [
                'value' => (int) ($counts[$barangay->id] ?? 0),
                'population' => $barangay->population,
                'households' => $barangay->households,
                'households_count' => $barangay->households_count ?? 0,
                'extension_activity_count' => $barangay->extension_activities_count,
            ],
        );
    }

    /**
     * Household survey filtered view — total responses per barangay, filtered by barangayIds
     */
    public function householdSurveyFilteredGeoJson(int $surveyId, array $barangayIds): array
    {
        $counts = SurveyResponse::query()
            ->selectRaw('barangay_id, COUNT(*) as aggregate')
            ->where('survey_id', $surveyId)
            ->whereNotNull('barangay_id')
            ->whereIn('barangay_id', $barangayIds)
            ->groupBy('barangay_id')
            ->pluck('aggregate', 'barangay_id');

        $barangays = Barangay::whereIn('id', $barangayIds)->withCount(['extensionActivities'])->withCount('households')->get();
        // Also include choropleth for filtered barangays only
        return $this->buildFeatureCollection(
            $barangays,
            function (Barangay $barangay) use ($counts, $surveyId) {
                $allMetrics = $this->hulagwayMetricsForBarangay($barangay->id, $surveyId);
                return [
                    'value' => (int) ($counts[$barangay->id] ?? 0),
                    'population' => $barangay->population,
                    'households' => $barangay->households,
                    'households_count' => $barangay->households_count ?? 0,
                    'extension_activity_count' => $barangay->extension_activities_count,
                    'hulagway' => $allMetrics,
                    'metric' => 'total_responses',
                ];
            }
        );
    }

    /**
     * Household survey multi-field choropleth — computes per-barangay breakdown for selected fields
     * @param array<int> $questionIds
     * @param array<string> $fieldCodes
     * @param array<int>|null $barangayIds
     */
    public function householdSurveyMultiFieldGeoJson(int $surveyId, ?array $questionIds, ?array $fieldCodes, ?array $barangayIds): array
    {
        $questionIds = $questionIds ? array_map('intval', $questionIds) : [];
        $fieldCodes = $fieldCodes ? array_map('strval', $fieldCodes) : [];

        // Determine barangays to include
        $barangaysQuery = Barangay::withCount(['extensionActivities'])->withCount('households');
        if ($barangayIds) {
            $barangaysQuery->whereIn('id', $barangayIds);
        }
        $barangays = $barangaysQuery->get();

        // Compute per-barangay breakdown
        $fieldBreakdowns = []; // [barangay_id => [field => count]]
        $totalPerBarangay = []; // [barangay_id => total for choropleth]

        foreach ($barangays as $barangay) {
            $bid = $barangay->id;
            $fieldBreakdowns[$bid] = [];
            $total = 0;

            // Handle questionIds (survey_questions)
            foreach ($questionIds as $qid) {
                $question = \App\Models\SurveyQuestion::find($qid);
                if (!$question || (int)$question->survey_id !== $surveyId) continue;
                $counts = $this->countsForChoiceQuestion($qid, $surveyId, null);
                // For household survey, countsForChoiceQuestion already handles barangay grouping, but we need per barangay
                $val = $counts[$bid] ?? 0;
                // For likert/text, we count total responses for that question per barangay
                $fieldBreakdowns[$bid][$question->question_text] = (int) $val;
                $total += (int) $val;
            }

            // Handle fieldCodes (housing, poor_cat2, pwd etc, plus gov fields)
            foreach ($fieldCodes as $code) {
                $val = 0;
                switch ($code) {
                    case 'housing':
                    case 'land':
                    case 'electricity':
                    case 'water':
                    case 'toilet':
                    case 'livelihood':
                        // Find question by code and count per barangay
                        $q = \App\Models\SurveyQuestion::where('survey_id', $surveyId)->where('code', $code)->first();
                        if ($q) {
                            $counts = $this->countsForChoiceQuestion($q->id, $surveyId, null);
                            $val = $counts[$bid] ?? 0;
                            $fieldBreakdowns[$bid][$q->question_text] = (int) $val;
                        }
                        break;
                    case 'poor_cat2':
                        $counts = $this->countsFromSurveyAnswers($surveyId, 'poor_cat2', ['Category 1%', 'Category 2%']);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Poor Family Category - Cat2'] = (int) $val;
                        break;
                    case 'live_in':
                        $counts = \App\Models\Household::where('live_in_status','Yes')->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId))->selectRaw('barangay_id, COUNT(*) as aggregate')->groupBy('barangay_id')->pluck('aggregate','barangay_id');
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Live-in Households'] = (int) $val;
                        break;
                    case 'gov_service_like':
                        $q = \App\Models\SurveyQuestion::where('survey_id', $surveyId)->where('code','gov_service_like')->first();
                        if ($q) {
                            $counts = $this->countsForChoiceQuestion($q->id, $surveyId, null);
                            $val = $counts[$bid] ?? 0;
                            $fieldBreakdowns[$bid][$q->question_text] = (int) $val;
                        }
                        break;
                    case 'gov_satisfaction':
                        $q = \App\Models\SurveyQuestion::where('survey_id', $surveyId)->where('code','gov_satisfaction')->first();
                        if ($q) {
                            $counts = $this->countsForChoiceQuestion($q->id, $surveyId, null);
                            $val = $counts[$bid] ?? 0;
                            $fieldBreakdowns[$bid][$q->question_text] = (int) $val;
                        }
                        break;
                    case 'suggestions':
                        $q = \App\Models\SurveyQuestion::where('survey_id', $surveyId)->where('code','suggestions')->first();
                        if ($q) {
                            $counts = $this->countsForChoiceQuestion($q->id, $surveyId, null);
                            $val = $counts[$bid] ?? 0;
                            $fieldBreakdowns[$bid][$q->question_text] = (int) $val;
                        }
                        break;
                    case 'pwd':
                    case 'pwd_cat2':
                        $counts = $this->countsFromMembers('is_pwd', ['Yes - Makalakaw pa','Yes - Di na ka lakaw'], $surveyId);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['PWD'] = (int) $val;
                        break;
                    case 'mentally':
                    case 'mentally_cat2':
                        $counts = $this->countsFromMembers('is_mentally_challenged', ['Yes - dili problema sa katilingban','Yes - hasol sa katilingban'], $surveyId);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Mentally Challenged'] = (int) $val;
                        break;
                    case 'bedridden':
                    case 'bedridden_cat2':
                        $counts = $this->countsFromMembers('bedridden_status', ['Yes - Mabakod pa','Yes - Di na kabakod'], $surveyId);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Bedridden'] = (int) $val;
                        break;
                    case 'seniors_90':
                        $counts = \App\Models\HouseholdMember::where('age','>=',90)->whereHas('household', fn($q)=>$q->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId)))->join('households','households.id','=','household_members.household_id')->selectRaw('households.barangay_id, COUNT(*) as aggregate')->groupBy('households.barangay_id')->pluck('aggregate','households.barangay_id');
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Senior 90+'] = (int) $val;
                        break;
                    case 'seniors_60':
                        $counts = \App\Models\HouseholdMember::where(function($q){ $q->where('is_senior', true)->orWhere('age','>=',60); })->whereHas('household', fn($q)=>$q->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId)))->join('households','households.id','=','household_members.household_id')->selectRaw('households.barangay_id, COUNT(*) as aggregate')->groupBy('households.barangay_id')->pluck('aggregate','households.barangay_id');
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Senior 60+'] = (int) $val;
                        break;
                    case 'osy':
                        $counts = $this->countsFromMembers('is_osy', true, $surveyId);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['OSY'] = (int) $val;
                        break;
                    case 'pregnant':
                        $counts = $this->countsFromMembers('is_pregnant', true, $surveyId);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Pregnant'] = (int) $val;
                        break;
                    case 'lcr':
                        $counts = $this->countsFromMembers('lcr_registered', 'No', $surveyId); // Count of not registered? For Poorness, we count Yes? For LCR, count Yes?
                        // For LCR, count registered = Yes
                        $countsYes = $this->countsFromMembers('lcr_registered', 'Yes', $surveyId);
                        $val = $countsYes[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['LCR Registered'] = (int) $val;
                        break;
                    case 'katungdanan':
                        $counts = $this->countsFromMembers('katungdanan_status', 'Yes', $surveyId);
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Katungdanan'] = (int) $val;
                        break;
                    case 'total_households':
                        $counts = \App\Models\Household::whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId))->selectRaw('barangay_id, COUNT(*) as aggregate')->groupBy('barangay_id')->pluck('aggregate','barangay_id');
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Total Households'] = (int) $val;
                        break;
                    case 'total_members':
                        $counts = \App\Models\HouseholdMember::join('households','households.id','=','household_members.household_id')->whereHas('household', fn($q)=>$q->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId)))->selectRaw('households.barangay_id, COUNT(*) as aggregate')->groupBy('households.barangay_id')->pluck('aggregate','households.barangay_id');
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid]['Total Members'] = (int) $val;
                        break;
                    default:
                        // Try as question code
                        $counts = $this->countsFromSurveyAnswers($surveyId, $code, '%');
                        // For generic like, fallback to count per barangay for that code
                        $val = $counts[$bid] ?? 0;
                        $fieldBreakdowns[$bid][$code] = (int) $val;
                        break;
                }
                $total += (int) $val;
            }

            $totalPerBarangay[$bid] = $total;
        }

        // If no fields selected, fallback to total responses per barangay
        if (empty($questionIds) && empty($fieldCodes)) {
            $counts = SurveyResponse::where('survey_id', $surveyId)->selectRaw('barangay_id, COUNT(*) as aggregate')->groupBy('barangay_id')->pluck('aggregate','barangay_id');
            foreach ($barangays as $b) {
                $totalPerBarangay[$b->id] = (int) ($counts[$b->id] ?? 0);
                $fieldBreakdowns[$b->id]['Total Responses'] = (int) ($counts[$b->id] ?? 0);
            }
        }

        return $this->buildFeatureCollection(
            $barangays,
            function (Barangay $barangay) use ($totalPerBarangay, $fieldBreakdowns, $surveyId) {
                $bid = $barangay->id;
                $allMetrics = $this->hulagwayMetricsForBarangay($bid, $surveyId);
                // Ensure Poorness Category always included
                if (!isset($fieldBreakdowns[$bid]['Poor Family Category - Cat2'])) {
                    $counts = $this->countsFromSurveyAnswers($surveyId, 'poor_cat2', ['Category 1%', 'Category 2%']);
                    $fieldBreakdowns[$bid]['Poor Family Category - Cat2'] = (int) ($counts[$bid] ?? 0);
                }
                return [
                    'value' => (int) ($totalPerBarangay[$bid] ?? 0),
                    'population' => $barangay->population,
                    'households' => $barangay->households,
                    'households_count' => $barangay->households_count ?? 0,
                    'total_households' => (int) ($allMetrics['total_responses'] ?? 0),
                    'extension_activity_count' => $barangay->extension_activities_count,
                    'hulagway' => array_merge($allMetrics, ['selected_fields' => $fieldBreakdowns[$bid] ?? []]),
                    'metric' => 'household_multi',
                    'breakdown' => $fieldBreakdowns[$bid] ?? [],
                ];
            }
        );
    }

    /**
     * Hulagway face of community — per barangay counts for vulnerability metrics.
     * Metrics: seniors_90, bedridden_cat2, mentally_cat2, pwd_cat2, osy, live_in, poor_cat2, pregnant
     * Category 2 only for poor families etc. per spec.
     */
    public function hulagwayGeoJson(?int $surveyId, string $metric): array
    {
        $barangays = Barangay::withCount(['extensionActivities'])->withCount('households')->get();
        $counts = collect([]);

        // Map metric to query — per-member now stored in household_members (new spec), with fallback to legacy survey_answers
        switch ($metric) {
            case 'seniors_90':
                $counts = \App\Models\HouseholdMember::whereHas('household', fn($q)=>$q->when($surveyId, fn($qq)=>$qq->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId))))
                    ->where('age', '>=', 90)
                    ->join('households','households.id','=','household_members.household_id')
                    ->selectRaw('households.barangay_id, COUNT(*) as aggregate')
                    ->groupBy('households.barangay_id')
                    ->pluck('aggregate','households.barangay_id');
                if ($counts->isEmpty()) {
                    $counts = $this->countsFromSurveyAnswers($surveyId, 'seniors_90', 'Yes');
                }
                break;
            case 'bedridden_cat2':
                $counts = $this->countsFromMembers('bedridden_status', ['Yes - Mabakod pa','Yes - Di na kabakod'], $surveyId);
                if ($counts->isEmpty()) $counts = $this->countsFromSurveyAnswers($surveyId, 'bedridden_cat2', 'Yes');
                break;
            case 'mentally_cat2':
                $counts = $this->countsFromMembers('is_mentally_challenged', ['Yes - dili problema sa katilingban','Yes - hasol sa katilingban'], $surveyId);
                if ($counts->isEmpty()) $counts = $this->countsFromSurveyAnswers($surveyId, 'mentally_cat2', 'Yes');
                break;
            case 'pwd_cat2':
                $counts = $this->countsFromMembers('is_pwd', ['Yes - Makalakaw pa','Yes - Di na ka lakaw'], $surveyId);
                if ($counts->isEmpty()) $counts = $this->countsFromSurveyAnswers($surveyId, 'pwd_cat2', 'Yes');
                break;
            case 'osy':
                $counts = $this->countsFromMembers('is_osy', true, $surveyId);
                if ($counts->isEmpty()) $counts = $this->countsFromSurveyAnswers($surveyId, 'osy', 'Yes');
                break;
            case 'live_in':
                // Household-level per new spec: households.live_in_status = Yes (with fallback to survey_answers)
                $q = \App\Models\Household::query()->where('live_in_status','Yes')->when($surveyId, fn($qq)=>$qq->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId)));
                $counts = $q->selectRaw('barangay_id, COUNT(*) as aggregate')->groupBy('barangay_id')->pluck('aggregate','barangay_id');
                if ($counts->isEmpty()) $counts = $this->countsFromSurveyAnswers($surveyId, 'live_in', 'Yes');
                break;
            case 'poor_cat2':
                $counts = $this->countsFromSurveyAnswers($surveyId, 'poor_cat2', ['Category 1%', 'Category 2%']);
                break;
            case 'pregnant':
                $counts = $this->countsFromMembers('is_pregnant', true, $surveyId);
                if ($counts->isEmpty()) $counts = $this->countsFromSurveyAnswers($surveyId, 'pregnant', 'Yes');
                break;
            default:
                $counts = collect([]);
        }

        // Build feature collection with hulagway details for panel
        return $this->buildFeatureCollection(
            $barangays,
            function (Barangay $barangay) use ($counts, $surveyId, $metric) {
                // For detail panel, compute all metrics for this barangay
                $allMetrics = $this->hulagwayMetricsForBarangay($barangay->id, $surveyId);
                return [
                    'value' => (int) ($counts[$barangay->id] ?? 0),
                    'population' => $barangay->population,
                    'households' => $barangay->households,
                    'households_count' => $barangay->households_count ?? 0,
                    'extension_activity_count' => $barangay->extension_activities_count,
                    'hulagway' => $allMetrics,
                    'metric' => $metric,
                ];
            }
        );
    }

    /**
     * Helper to get counts from survey_answers by question code.
     * Individual-scope: COUNT(DISTINCT household_member_id) = persons.
     * Household-scope: COUNT(DISTINCT household_id) = households.
     *
     * @param  string|array<int, string>  $like
     */
    private function countsFromSurveyAnswers(?int $surveyId, string $code, string|array $like): \Illuminate\Support\Collection
    {
        $query = \Illuminate\Support\Facades\DB::table('survey_answers')
            ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
            ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
            ->where('survey_questions.code', $code)
            ->whereNotNull('survey_responses.barangay_id');

        if (is_array($like)) {
            $query->where(function ($q) use ($like) {
                foreach ($like as $i => $pattern) {
                    $q->orWhere('survey_answers.answer', 'like', $pattern);
                }
            });
        } else {
            $query->where('survey_answers.answer', 'like', $like);
        }

        if ($surveyId) {
            $query->where('survey_responses.survey_id', $surveyId);
        }

        // Household-scope codes (poor_cat2) count households, individual counts persons
        $householdCodes = ['poor_cat2'];
        if (in_array($code, $householdCodes, true)) {
            return $query->selectRaw('survey_responses.barangay_id, COUNT(DISTINCT survey_responses.household_id) as aggregate')
                ->groupBy('survey_responses.barangay_id')
                ->pluck('aggregate','survey_responses.barangay_id');
        }

        // Individual: count distinct member ids (persons), exclude household-level rows
        return $query->whereNotNull('survey_answers.household_member_id')
            ->selectRaw('survey_responses.barangay_id, COUNT(DISTINCT survey_answers.household_member_id) as aggregate')
            ->groupBy('survey_responses.barangay_id')
            ->pluck('aggregate','survey_responses.barangay_id');
    }

    /**
     * Per-member counts from household_members table (new spec).
     */
    private function countsFromMembers(string $field, $value, ?int $surveyId): \Illuminate\Support\Collection
    {
        $q = \App\Models\HouseholdMember::join('households','households.id','=','household_members.household_id')
            ->whereNotNull('households.barangay_id')
            ->when($surveyId, fn($qq)=>$qq->whereHas('household', fn($hq)=>$hq->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId))));
        if (is_array($value)) {
            $q->whereIn("household_members.$field", $value);
        } elseif (is_bool($value)) {
            $q->where("household_members.$field", $value ? 1 : 0);
        } else {
            $q->where("household_members.$field", $value);
        }
        return $q->selectRaw('households.barangay_id, COUNT(DISTINCT households.id) as aggregate')->groupBy('households.barangay_id')->pluck('aggregate','households.barangay_id');
    }

    /**
     * All hulagway metrics for a single barangay (for detail panel).
     */
    private function hulagwayMetricsForBarangay(int $barangayId, ?int $surveyId): array
    {
        $metrics = ['seniors_90','bedridden_cat2','mentally_cat2','pwd_cat2','osy','live_in','poor_cat2','pregnant'];
        $householdCodes = ['poor_cat2'];
        $result = [];
        foreach ($metrics as $m) {
            $like = $m === 'poor_cat2' ? ['Category 1%', 'Category 2%'] : 'Yes';
            if ($m === 'seniors_90') {
                // Try household_members first (persons)
                $count = \App\Models\HouseholdMember::whereHas('household', fn($q)=>$q->where('barangay_id',$barangayId))
                    ->where('age','>=',90)->count();
                if ($count === 0) {
                    $count = \Illuminate\Support\Facades\DB::table('survey_answers')
                        ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code','seniors_90')
                        ->where('survey_answers.answer','like','Yes')
                        ->whereNotNull('survey_answers.household_member_id')
                        ->where('survey_responses.barangay_id',$barangayId)
                        ->when($surveyId, fn($q)=>$q->where('survey_responses.survey_id',$surveyId))
                        ->distinct()->count('survey_answers.household_member_id');
                }
                $result[$m] = $count;
            } elseif ($m === 'live_in') {
                // Household-level per new spec
                $count = \App\Models\Household::where('barangay_id',$barangayId)->where('live_in_status','Yes')
                    ->when($surveyId, fn($q)=>$q->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId)))->count();
                if ($count === 0) {
                    $count = \Illuminate\Support\Facades\DB::table('survey_answers')
                        ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code',$m)->where('survey_answers.answer','like','Yes')
                        ->where('survey_responses.barangay_id',$barangayId)
                        ->when($surveyId, fn($q)=>$q->where('survey_responses.survey_id',$surveyId))
                        ->distinct()->count('survey_responses.household_id');
                }
                $result[$m] = $count;
            } elseif (in_array($m, ['pwd_cat2','mentally_cat2','osy','pregnant','bedridden_cat2'], true)) {
                // Per-member from household_members, fallback to legacy survey_answers
                $fieldMap = [
                    'pwd_cat2' => ['field'=>'is_pwd','value'=>['Yes - Makalakaw pa','Yes - Di na ka lakaw']],
                    'mentally_cat2' => ['field'=>'is_mentally_challenged','value'=>['Yes - dili problema sa katilingban','Yes - hasol sa katilingban']],
                    'osy' => ['field'=>'is_osy','value'=>1],
                    'pregnant' => ['field'=>'is_pregnant','value'=>1],
                    'bedridden_cat2' => ['field'=>'bedridden_status','value'=>['Yes - Mabakod pa','Yes - Di na kabakod']],
                ];
                $map = $fieldMap[$m];
                $q = \App\Models\HouseholdMember::whereHas('household', fn($hq)=>$hq->where('barangay_id',$barangayId))
                    ->when($surveyId, fn($qq)=>$qq->whereHas('household', fn($hq)=>$hq->whereHas('responses', fn($r)=>$r->where('survey_id',$surveyId))));
                if (is_array($map['value'])) {
                    $q->whereIn($map['field'], $map['value']);
                } else {
                    $q->where($map['field'], $map['value']);
                }
                $count = $q->count();
                if ($count === 0) {
                    $count = \Illuminate\Support\Facades\DB::table('survey_answers')
                        ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code',$m)->where('survey_answers.answer','like','Yes')
                        ->whereNotNull('survey_answers.household_member_id')
                        ->where('survey_responses.barangay_id',$barangayId)
                        ->when($surveyId, fn($q)=>$q->where('survey_responses.survey_id',$surveyId))
                        ->distinct()->count('survey_answers.household_member_id');
                }
                $result[$m] = $count;
            } else {
                $q = \Illuminate\Support\Facades\DB::table('survey_answers')
                    ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                    ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                    ->where('survey_questions.code',$m)
                    ->where(function ($query) use ($like) {
                        if (is_array($like)) {
                            foreach ($like as $pattern) {
                                $query->orWhere('survey_answers.answer', 'like', $pattern);
                            }
                        } else {
                            $query->where('survey_answers.answer', 'like', $like);
                        }
                    })
                    ->where('survey_responses.barangay_id',$barangayId)
                    ->when($surveyId, fn($q)=>$q->where('survey_responses.survey_id',$surveyId));
                if (in_array($m, $householdCodes, true)) {
                    $result[$m] = $q->distinct()->count('survey_responses.household_id');
                } else {
                    $result[$m] = $q->whereNotNull('survey_answers.household_member_id')->distinct()->count('survey_answers.household_member_id');
                }
            }
        }
        // Add household and total responses for context
        $result['households'] = \App\Models\Household::where('barangay_id',$barangayId)->count();
        $result['total_responses'] = \App\Models\SurveyResponse::where('barangay_id',$barangayId)->when($surveyId, fn($q)=>$q->where('survey_id',$surveyId))->count();
        return $result;
    }

    /**
     * Fully dynamic per-question aggregation — no code required.
     * Supports household age buckets (children/adult/seniors) and sex (Male/Female) per barangay.
     */
    public function genericQuestionGeoJson(?int $surveyId, ?int $questionId, ?string $answer, ?string $householdField, ?string $bucket): array
    {
        $barangays = Barangay::withCount(['extensionActivities'])->withCount('households')->get();
        $counts = collect([]);

        // Household field path (age/sex) — no survey question needed, uses household_members
        if ($householdField) {
            $counts = $this->countsForHouseholdField($householdField, $bucket);
            // Detail panel: still include all hulagway for context, but value is from household field
            return $this->buildFeatureCollection($barangays, function (Barangay $b) use ($counts, $householdField, $bucket) {
                $all = $this->hulagwayMetricsForBarangay($b->id, null);
                // Add household age/sex breakdown for detail
                $breakdown = $this->householdFieldBreakdown($b->id);
                return [
                    'value' => (int) ($counts[$b->id] ?? 0),
                    'population' => $b->population,
                    'households' => $b->households,
                    'households_count' => $b->households_count ?? 0,
                    'extension_activity_count' => $b->extension_activities_count,
                    'hulagway' => array_merge($all, $breakdown),
                    'metric' => $householdField . ($bucket ? ':'.$bucket : ''),
                ];
            });
        }

        // Survey question path
        if ($questionId) {
            $question = \App\Models\SurveyQuestion::find($questionId);
            if (! $question) {
                return $this->buildFeatureCollection($barangays, fn($b)=>['value'=>0,'population'=>$b->population,'households'=>$b->households,'households_count'=>$b->households_count??0,'extension_activity_count'=>$b->extension_activities_count,'hulagway'=>[],'metric'=>'unknown']);
            }
            // Numeric age-like question: bucket into children/adult/seniors if bucket requested or type is number
            if ($question->type === 'number' || strtolower($question->question_text) === 'edad' || str_contains(strtolower($question->question_text),'age')) {
                // If bucket specified, filter to that bucket
                $counts = $this->countsForNumericQuestion($question->id, $surveyId, $bucket, $answer);
            } else {
                // Choice / text: count per answer value
                $counts = $this->countsForChoiceQuestion($question->id, $surveyId, $answer);
            }
            return $this->buildFeatureCollection($barangays, function (Barangay $b) use ($counts, $question) {
                $all = $this->hulagwayMetricsForBarangay($b->id, null);
                // Also include breakdown for this specific question per barangay
                $breakdown = $this->choiceBreakdownForQuestion($question->id);
                return [
                    'value' => (int) ($counts[$b->id] ?? 0),
                    'population' => $b->population,
                    'households' => $b->households,
                    'households_count' => $b->households_count ?? 0,
                    'extension_activity_count' => $b->extension_activities_count,
                    'hulagway' => array_merge($all, ['question_breakdown'=>$breakdown]),
                    'metric' => $question->question_text,
                ];
            });
        }

        return $this->buildFeatureCollection($barangays, fn($b)=>['value'=>0,'population'=>$b->population,'households'=>$b->households,'households_count'=>0,'extension_activity_count'=>0,'hulagway'=>[],'metric'=>'']);
    }

    private function countsForHouseholdField(string $field, ?string $bucket): \Illuminate\Support\Collection
    {
        if ($field === 'sex') {
            $like = $bucket ?? null;
            $q = \App\Models\HouseholdMember::join('households','households.id','=','household_members.household_id')
                ->selectRaw('households.barangay_id, COUNT(*) as aggregate')
                ->groupBy('households.barangay_id');
            if ($like && in_array($like, ['Male','Female','Others'])) {
                $q->where('household_members.sex', $like);
            }
            return $q->pluck('aggregate','households.barangay_id');
        }
        if ($field === 'age') {
            $q = \App\Models\HouseholdMember::join('households','households.id','=','household_members.household_id')
                ->selectRaw('households.barangay_id, COUNT(*) as aggregate')
                ->groupBy('households.barangay_id');
            if ($bucket === 'children') $q->whereBetween('household_members.age', [0,17]);
            elseif ($bucket === 'adult') $q->whereBetween('household_members.age', [18,59]);
            elseif ($bucket === 'seniors') $q->where('household_members.age','>=',60);
            elseif ($bucket === 'seniors_90') $q->where('household_members.age','>=',90);
            // else no bucket = total members
            return $q->pluck('aggregate','households.barangay_id');
        }
        return collect([]);
    }

    private function householdFieldBreakdown(int $barangayId): array
    {
        $base = \App\Models\HouseholdMember::whereHas('household', fn($q)=>$q->where('barangay_id',$barangayId));
        return [
            'household_children' => (clone $base)->whereBetween('age',[0,17])->count(),
            'household_adult' => (clone $base)->whereBetween('age',[18,59])->count(),
            'household_seniors' => (clone $base)->where('age','>=',60)->count(),
            'household_male' => (clone $base)->where('sex','Male')->count(),
            'household_female' => (clone $base)->where('sex','Female')->count(),
        ];
    }

    private function countsForChoiceQuestion(int $questionId, ?int $surveyId, ?string $answer): \Illuminate\Support\Collection
    {
        $question = \App\Models\SurveyQuestion::find($questionId);
        $isIndividual = $question && $question->data_scope === \App\Models\SurveyQuestion::DATA_SCOPE_INDIVIDUAL;
        $q = \Illuminate\Support\Facades\DB::table('survey_answers')
            ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
            ->where('survey_answers.survey_question_id', $questionId)
            ->whereNotNull('survey_responses.barangay_id');
        if ($surveyId) $q->where('survey_responses.survey_id', $surveyId);
        if ($answer !== null && $answer !== '') {
            // For multiple_choice stored as JSON array, need LIKE
            $q->where('survey_answers.answer','like','%'.$answer.'%');
        }
        if ($isIndividual) {
            $q->whereNotNull('survey_answers.household_member_id');
            return $q->selectRaw('survey_responses.barangay_id, COUNT(DISTINCT survey_answers.household_member_id) as aggregate')->groupBy('survey_responses.barangay_id')->pluck('aggregate','survey_responses.barangay_id');
        }
        // Household/response scope: count responses (or distinct households for household scope)
        if ($question && $question->data_scope === \App\Models\SurveyQuestion::DATA_SCOPE_HOUSEHOLD) {
            return $q->selectRaw('survey_responses.barangay_id, COUNT(DISTINCT survey_responses.household_id) as aggregate')->groupBy('survey_responses.barangay_id')->pluck('aggregate','survey_responses.barangay_id');
        }
        return $q->selectRaw('survey_responses.barangay_id, COUNT(*) as aggregate')->groupBy('survey_responses.barangay_id')->pluck('aggregate','survey_responses.barangay_id');
    }

    private function countsForNumericQuestion(int $questionId, ?int $surveyId, ?string $bucket, ?string $answer): \Illuminate\Support\Collection
    {
        if ($bucket) {
            return $this->countsForChoiceQuestion($questionId, $surveyId, null)->pipe(function($c) use ($questionId,$surveyId,$bucket){
                // For numeric buckets, filter by range if answer is numeric
                $ranges = ['children'=>[0,17],'adult'=>[18,59],'seniors'=>[60,200],'seniors_90'=>[90,200]];
                if (!isset($ranges[$bucket])) return $c;
                [$min,$max] = $ranges[$bucket];
                $q = \Illuminate\Support\Facades\DB::table('survey_answers')
                    ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                    ->where('survey_answers.survey_question_id',$questionId)
                    ->whereNotNull('survey_responses.barangay_id')
                    ->whereRaw('CAST(survey_answers.answer AS DECIMAL) BETWEEN ? AND ?', [$min,$max]);
                if ($surveyId) $q->where('survey_responses.survey_id',$surveyId);
                return $q->selectRaw('survey_responses.barangay_id, COUNT(*) as aggregate')->groupBy('survey_responses.barangay_id')->pluck('aggregate','survey_responses.barangay_id');
            });
        }
        // No bucket: total responses for that question per barangay
        return $this->countsForChoiceQuestion($questionId, $surveyId, $answer);
    }

    private function choiceBreakdownForQuestion(int $questionId): array
    {
        return \Illuminate\Support\Facades\DB::table('survey_answers')
            ->where('survey_question_id',$questionId)
            ->selectRaw('answer, COUNT(*) as cnt')
            ->groupBy('answer')
            ->pluck('cnt','answer')->toArray();
    }

    /**
     * Itemized per-barangay aggregation for the map dialogs.
     * For choice questions returns a per-answer breakdown (and text questions their
     * actual typed values), scoped to a barangay (or all barangays).
     *
     * @param  array<int>|null  $questionIds
     * @param  array<string>|null  $fieldCodes
     * @param  array<int>|null  $barangayIds
     * @return array<string, mixed>
     */
    public function aggregationDetail(?int $surveyId, ?array $questionIds, ?array $fieldCodes, ?array $barangayIds): array
    {
        if (! $surveyId || (empty($questionIds) && empty($fieldCodes))) {
            return ['barangays' => []];
        }
        $questionIds = $questionIds ? array_map('intval', $questionIds) : [];
        $fieldCodes = $fieldCodes ? array_map('strval', $fieldCodes) : [];

        // Reuse the multi-field builder for per-barangay totals / breakdown
        $fc = $this->householdSurveyMultiFieldGeoJson($surveyId, $questionIds, $fieldCodes, $barangayIds);
        $breakdownByBarangay = [];
        foreach ($fc['features'] as $f) {
            $breakdownByBarangay[(int) $f['id']] = $f['properties']['breakdown'] ?? [];
        }

        // Per-field answer detail (one query per question, grouped by barangay in PHP)
        $questionItems = [];
        foreach ($questionIds as $qid) {
            $question = \App\Models\SurveyQuestion::find($qid);
            if (! $question || (int) $question->survey_id !== $surveyId) {
                continue;
            }
            $questionItems[$qid] = [
                'question' => $question,
                'byBarangay' => $this->questionItemsByBarangay($question, $surveyId, $barangayIds),
            ];
        }

        // Field-code display info + breakdown key
        $fieldInfo = [];
        foreach ($fieldCodes as $code) {
            [$key, $label] = $this->fieldCodeInfo($code);
            $fieldInfo[$code] = ['key' => $key, 'label' => $label];
        }

        // Member-attribute codes itemized per answer selection (counted per household)
        $choiceMemberFields = [
            'pwd' => ['col' => 'is_pwd', 'options' => ['Yes - Makalakaw pa', 'Yes - Di na ka lakaw', 'No']],
            'pwd_cat2' => ['col' => 'is_pwd', 'options' => ['Yes - Makalakaw pa', 'Yes - Di na ka lakaw', 'No']],
            'mentally' => ['col' => 'is_mentally_challenged', 'options' => ['Yes - dili problema sa katilingban', 'Yes - hasol sa katilingban', 'No']],
            'mentally_cat2' => ['col' => 'is_mentally_challenged', 'options' => ['Yes - dili problema sa katilingban', 'Yes - hasol sa katilingban', 'No']],
        ];
        $memberItems = [];
        foreach ($choiceMemberFields as $code => $def) {
            $memberItems[$code] = $this->memberValueItemsByBarangay($def['col'], $surveyId, $barangayIds);
        }

        // Poor Family is a survey question -> itemize over its configured options
        $poorItems = [];
        $poorOptions = [];
        if (in_array('poor_cat2', $fieldCodes, true)) {
            $poorQuestion = \App\Models\SurveyQuestion::where('survey_id', $surveyId)->where('code', 'poor_cat2')->first();
            if ($poorQuestion) {
                $poorItems = $this->questionItemsByBarangay($poorQuestion, $surveyId, $barangayIds);
                $poorOptions = $poorQuestion->options->pluck('label')->all();
            }
        }

        $barangays = [];
        foreach ($fc['features'] as $f) {
            $bid = (int) $f['id'];
            $fields = [];

            foreach ($questionIds as $qid) {
                if (! isset($questionItems[$qid])) {
                    continue;
                }
                $question = $questionItems[$qid]['question'];
                $items = $questionItems[$qid]['byBarangay'][$bid] ?? [];
                $type = $this->answerType($question);
                $fields[] = [
                    'key' => 'q_'.$qid,
                    'label' => $question->code === 'poor_cat2' ? 'Poor Family' : $question->question_text,
                    'type' => $type,
                    'total' => array_sum(array_column($items, 'count')),
                    'items' => $items,
                ];
            }

            foreach ($fieldCodes as $code) {
                if (! isset($fieldInfo[$code])) {
                    continue;
                }
                $key = $fieldInfo[$code]['key'];
                $label = $fieldInfo[$code]['label'];

                // Poor Family — itemized over its 3 survey answers
                if ($code === 'poor_cat2') {
                    $items = $this->padChoiceItems($poorItems[$bid] ?? [], $poorOptions);
                    $fields[] = [
                        'key' => 'field_'.$code,
                        'label' => $label,
                        'type' => 'choice',
                        'total' => array_sum(array_column($items, 'count')),
                        'items' => $items,
                    ];

                    continue;
                }

                // Member attributes — itemized per answer, padded with all options
                if (isset($choiceMemberFields[$code])) {
                    $items = $this->padChoiceItems($memberItems[$code][$bid] ?? [], $choiceMemberFields[$code]['options']);
                    $fields[] = [
                        'key' => 'field_'.$code,
                        'label' => $label,
                        'type' => 'choice',
                        'total' => array_sum(array_column($items, 'count')),
                        'items' => $items,
                    ];

                    continue;
                }

                // Scalar aggregates
                $fields[] = [
                    'key' => 'field_'.$code,
                    'label' => $label,
                    'type' => 'count',
                    'total' => (int) ($breakdownByBarangay[$bid][$key] ?? 0),
                    'items' => [],
                ];
            }

            $barangays[] = [
                'id' => $bid,
                'name' => $f['properties']['name'] ?? "Barangay #{$bid}",
                'value' => (int) ($f['properties']['value'] ?? 0),
                'households' => (int) ($f['properties']['total_households'] ?? 0),
                'fields' => $fields,
            ];
        }

        return ['barangays' => $barangays];
    }

    /**
     * Per-value (answer) distribution for a household_member attribute, counted per
     * household, grouped by barangay.
     *
     * @param  array<int>|null  $barangayIds
     * @return array<int, array<int, array{name: string, count: int}>>
     */
    private function memberValueItemsByBarangay(string $column, int $surveyId, ?array $barangayIds): array
    {
        $base = \App\Models\HouseholdMember::join('households', 'households.id', '=', 'household_members.household_id')
            ->whereNotNull('households.barangay_id')
            ->whereNotNull("household_members.$column")
            ->when($surveyId, fn ($q) => $q->whereHas('household', fn ($hq) => $hq->whereHas('responses', fn ($r) => $r->where('survey_id', $surveyId))));
        if ($barangayIds) {
            $base->whereIn('households.barangay_id', $barangayIds);
        }

        $rows = $base->selectRaw("households.barangay_id as barangay_id, household_members.$column as val, COUNT(DISTINCT households.id) as cnt")
            ->groupBy('households.barangay_id', "household_members.$column")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->barangay_id][] = ['name' => (string) $row->val, 'count' => (int) $row->cnt];
        }

        return $result;
    }

    /**
     * Ensure every allowed option is present in the items list (count 0 if absent).
     *
     * @param  array<int, string>  $options
     * @return array<int, array{name: string, count: int}>
     */
    private function padChoiceItems(array $items, array $options): array
    {
        $existing = array_column($items, 'name');
        foreach ($options as $option) {
            if (! in_array($option, $existing, true)) {
                $items[] = ['name' => $option, 'count' => 0];
            }
        }
        usort($items, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return $items;
    }

    /**
     * Per-answer tally for one question, grouped by barangay.
     *
     * @param  array<int>|null  $barangayIds
     * @return array<int, array<int, array{name: string, count: int}>>
     */
    private function questionItemsByBarangay(\App\Models\SurveyQuestion $question, int $surveyId, ?array $barangayIds): array
    {
        $scope = $question->data_scope ?: \App\Models\SurveyQuestion::DATA_SCOPE_RESPONSE;
        $base = \Illuminate\Support\Facades\DB::table('survey_answers')
            ->join('survey_responses', 'survey_responses.id', '=', 'survey_answers.survey_response_id')
            ->where('survey_answers.survey_question_id', $question->id)
            ->where('survey_responses.survey_id', $surveyId);
        if ($barangayIds) {
            $base->whereIn('survey_responses.barangay_id', $barangayIds);
        }
        if ($scope === \App\Models\SurveyQuestion::DATA_SCOPE_INDIVIDUAL) {
            $base->whereNotNull('survey_answers.household_member_id');
        }
        $rows = $base->select(
            'survey_responses.barangay_id as barangay_id',
            'survey_answers.answer',
            'survey_answers.household_member_id',
            'survey_responses.household_id',
            'survey_responses.id as response_id',
        )->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->barangay_id][] = $row;
        }

        $result = [];
        foreach ($grouped as $bid => $barangayRows) {
            $result[$bid] = $this->tallyItems($question, $scope, $barangayRows);
        }

        return $result;
    }

    private function tallyItems(\App\Models\SurveyQuestion $question, string $scope, iterable $rows): array
    {
        // Text answers -> list of typed values
        if (in_array($question->type, [\App\Models\SurveyQuestion::TYPE_TEXT, \App\Models\SurveyQuestion::TYPE_TEXTAREA], true)) {
            $counts = [];
            foreach ($rows as $row) {
                $text = trim((string) $row->answer);
                if ($text === '' || $text === '[]') {
                    continue;
                }
                $counts[$text] = ($counts[$text] ?? 0) + 1;
            }
            $items = [];
            foreach ($counts as $name => $count) {
                $items[] = ['name' => $name, 'count' => $count];
            }
            usort($items, fn ($a, $b) => $b['count'] <=> $a['count']);

            return array_slice($items, 0, 200);
        }

        // Choice answers -> distinct units (member/household/response) per option
        $tally = [];
        foreach ($rows as $row) {
            $answers = $this->expandAnswer($question->type, $row->answer);
            $unit = match ($scope) {
                \App\Models\SurveyQuestion::DATA_SCOPE_INDIVIDUAL => $row->household_member_id,
                \App\Models\SurveyQuestion::DATA_SCOPE_HOUSEHOLD => $row->household_id,
                default => $row->response_id,
            };
            foreach ($answers as $answer) {
                $answer = trim((string) $answer);
                if ($answer === '') {
                    continue;
                }
                $tally[$answer][$unit] = true;
            }
        }
        $items = [];
        foreach ($tally as $name => $units) {
            $items[] = ['name' => $name, 'count' => count($units)];
        }
        // Ensure configured options are always present (even with 0 responses)
        $existing = array_column($items, 'name');
        foreach ($question->options->pluck('label')->all() as $option) {
            if (! in_array($option, $existing, true)) {
                $items[] = ['name' => $option, 'count' => 0];
            }
        }
        usort($items, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return $items;
    }

    private function expandAnswer(string $type, ?string $answer): array
    {
        if ($answer === null || $answer === '') {
            return [];
        }
        if ($type === \App\Models\SurveyQuestion::TYPE_MULTIPLE_CHOICE) {
            $decoded = json_decode($answer, true);

            return is_array($decoded) ? $decoded : [$answer];
        }

        return [$answer];
    }

    private function answerType(\App\Models\SurveyQuestion $question): string
    {
        if (in_array($question->type, [\App\Models\SurveyQuestion::TYPE_TEXT, \App\Models\SurveyQuestion::TYPE_TEXTAREA], true)) {
            return 'text';
        }
        if (in_array($question->type, [
            \App\Models\SurveyQuestion::TYPE_SINGLE_CHOICE,
            \App\Models\SurveyQuestion::TYPE_DROPDOWN,
            \App\Models\SurveyQuestion::TYPE_MULTIPLE_CHOICE,
            \App\Models\SurveyQuestion::TYPE_LIKERT,
        ], true)) {
            return 'choice';
        }

        return 'count';
    }

    /**
     * Map a field code to [breakdown-key, display-label].
     *
     * @return array{0: string, 1: string}
     */
    private function fieldCodeInfo(string $code): array
    {
        $map = [
            'poor_cat2' => ['Poor Family Category - Cat2', 'Poor Family'],
            'pwd' => ['PWD', 'PWD'],
            'pwd_cat2' => ['PWD', 'PWD'],
            'mentally' => ['Mentally Challenged', 'Mentally Challenged'],
            'mentally_cat2' => ['Mentally Challenged', 'Mentally Challenged'],
            'bedridden' => ['Bedridden', 'Bedridden'],
            'bedridden_cat2' => ['Bedridden', 'Bedridden'],
            'seniors_60' => ['Senior 60+', 'Senior 60+'],
            'seniors_90' => ['Senior 90+', 'Senior 90+'],
            'osy' => ['OSY', 'Out-of-School Youth'],
            'pregnant' => ['Pregnant', 'Pregnant'],
            'live_in' => ['Live-in Households', 'Live-in Households'],
            'housing' => ['Housing', 'Housing Condition'],
            'land' => ['Land', 'Land'],
            'electricity' => ['Electricity', 'Electricity'],
            'water' => ['Water', 'Water'],
            'toilet' => ['Toilet', 'Toilet'],
            'livelihood' => ['Livelihood', 'Livelihood'],
            'gov_service_like' => ['Government Service', 'Government Service'],
            'gov_satisfaction' => ['Govt Satisfaction', 'Govt Satisfaction'],
            'suggestions' => ['Suggestions', 'Suggestions'],
            'lcr' => ['LCR Registered', 'LCR Registered'],
            'katungdanan' => ['Katungdanan', 'Katungdanan'],
            'total_households' => ['Total Households', 'Total Households'],
            'total_members' => ['Total Members', 'Total Members'],
        ];

        return $map[$code] ?? [$code, ucwords(str_replace('_', ' ', $code))];
    }

    /**
     * Extension activity choropleth values + point markers.
     *
     * @return array{choropleth: array<string, mixed>, markers: array<string, mixed>}
     */
    /**
     * Extension activity choropleth values + point markers.
     *
     * @param  array<int>|null  $barangayIds
     * @return array{choropleth: array<string, mixed>, markers: array<string, mixed>}
     */
     /**
      * @param array<string>|null $statuses  e.g. ['ongoing','completed'] — null means all (including in-progress)
      */
     public function extensionsGeoJson(?int $programId = null, $status = null, ?array $barangayIds = null, ?array $sdgIds = null, ?int $year = null): array
     {
         // Normalize status param: string|null or array
         $statuses = null;
         if (is_array($status)) { $statuses = $status; }
         elseif (is_string($status) && $status !== '') { $statuses = [$status]; }

         $yearWhere = function ($q) use ($year) {
             if ($year) $q->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function($qq) use ($year){ $qq->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year); });
             // Simpler: activities whose start_date or end_date year matches, OR spans the year
         };

         $counts = ExtensionActivity::query()
            ->selectRaw('barangay_id, COUNT(*) as aggregate')
            ->whereNotNull('barangay_id')
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function($qq2) use ($year){
                    $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year);
                });
            }))
            ->when($barangayIds, fn ($q) => $q->whereIn('barangay_id', $barangayIds))
            ->when($sdgIds, fn ($q) => $q->whereHas('sdgs', fn ($sq) => $sq->whereIn('sdgs.id', $sdgIds)))
            ->groupBy('barangay_id')
            ->pluck('aggregate', 'barangay_id');

        // For hover itemization: per-barangay SDG breakdown (ANY logic)
        $sdgBreakdown = [];
        if (true) {
            $rows = \Illuminate\Support\Facades\DB::table('extension_activity_sdg')
                ->join('extension_activities', 'extension_activities.id', '=', 'extension_activity_sdg.extension_activity_id')
                ->join('sdgs', 'sdgs.id', '=', 'extension_activity_sdg.sdg_id')
                ->selectRaw('extension_activities.barangay_id, sdgs.id as sdg_id, sdgs.number, sdgs.title, sdgs.color, sdgs.icon_url, COUNT(DISTINCT extension_activities.id) as cnt')
                ->whereNotNull('extension_activities.barangay_id')
                ->whereNull('extension_activities.deleted_at')
                ->when($programId, fn ($q) => $q->where('extension_activities.program_id', $programId))
                ->when($statuses, fn ($q) => $q->whereIn('extension_activities.status', $statuses))
                ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                    $qq->whereYear('extension_activities.start_date', $year)->orWhereYear('extension_activities.end_date', $year)->orWhere(function($qq2) use ($year){
                        $qq2->whereYear('extension_activities.start_date','<=',$year)->whereYear('extension_activities.end_date','>=',$year);
                    });
                }))
                ->when($barangayIds, fn ($q) => $q->whereIn('extension_activities.barangay_id', $barangayIds))
                ->when($sdgIds, fn ($q) => $q->whereIn('sdgs.id', $sdgIds))
                ->groupBy('extension_activities.barangay_id', 'sdgs.id', 'sdgs.number', 'sdgs.title', 'sdgs.color', 'sdgs.icon_url')
                ->orderBy('sdgs.number')
                ->get();
            foreach ($rows as $r) {
                $sdgBreakdown[$r->barangay_id][] = [
                    'id' => (int) $r->sdg_id,
                    'number' => (int) $r->number,
                    'title' => $r->title,
                    'color' => $r->color,
                    'icon_url' => $r->icon_url,
                    'count' => (int) $r->cnt,
                ];
            }
        }

        // Program breakdown per barangay for dialog / hover (count + avg progress)
        $programBreakdown = [];
        $progRows = \Illuminate\Support\Facades\DB::table('extension_activities')
            ->join('programs','programs.id','=','extension_activities.program_id')
            ->selectRaw('extension_activities.barangay_id, programs.id as program_id, programs.name as program_name, COUNT(*) as cnt, AVG(extension_activities.progress) as avg_progress')
            ->whereNotNull('extension_activities.barangay_id')
            ->whereNull('extension_activities.deleted_at')
            ->when($programId, fn ($q) => $q->where('extension_activities.program_id', $programId))
            ->when($statuses, fn ($q) => $q->whereIn('extension_activities.status', $statuses))
            ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                $qq->whereYear('extension_activities.start_date', $year)->orWhereYear('extension_activities.end_date', $year)->orWhere(function($qq2) use ($year){
                    $qq2->whereYear('extension_activities.start_date','<=',$year)->whereYear('extension_activities.end_date','>=',$year);
                });
            }))
            ->when($barangayIds, fn ($q) => $q->whereIn('extension_activities.barangay_id', $barangayIds))
            ->when($sdgIds, fn ($q) => $q->whereExists(function($sq) use ($sdgIds){
                $sq->select(\Illuminate\Support\Facades\DB::raw(1))->from('extension_activity_sdg')->whereColumn('extension_activity_sdg.extension_activity_id','extension_activities.id')->whereIn('sdg_id',$sdgIds);
            }))
            ->groupBy('extension_activities.barangay_id','programs.id','programs.name')
            ->orderBy('programs.name')
            ->get();
        foreach ($progRows as $r) {
            $programBreakdown[$r->barangay_id][] = [
                'program_id' => (int)$r->program_id,
                'program_name' => $r->program_name,
                'count' => (int)$r->cnt,
                'avg_progress' => (int)round((float)$r->avg_progress),
            ];
        }

        // City-wide (barangay_id null) count — counted but not placed as marker
        $cityWideCount = ExtensionActivity::query()
            ->whereNull('barangay_id')
            ->whereNull('deleted_at')
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function($qq2) use ($year){
                    $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year);
                });
            }))
            ->when($sdgIds, fn ($q) => $q->whereHas('sdgs', fn ($sq) => $sq->whereIn('sdgs.id', $sdgIds)))
            ->count();

        // Extension details per barangay (for hover: show extension info + progress)
        $extensionDetails = [];
        $detailActivities = ExtensionActivity::query()
            ->with(['program:id,name','barangay:id,name'])
            ->whereNotNull('barangay_id')
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function($qq2) use ($year){
                    $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year);
                });
            }))
            ->when($barangayIds, fn ($q) => $q->whereIn('barangay_id', $barangayIds))
            ->when($sdgIds, fn ($q) => $q->whereHas('sdgs', fn ($sq) => $sq->whereIn('sdgs.id', $sdgIds)))
            ->get(['id','title','status','progress','program_id','barangay_id','location','start_date','end_date']);
        foreach ($detailActivities as $act) {
            $extensionDetails[$act->barangay_id][] = [
                'id' => $act->id,
                'title' => $act->title,
                'status' => $act->status,
                'progress' => (int)$act->progress,
                'program' => $act->program?->name,
                'location' => $act->location,
                'start_date' => $act->start_date?->toDateString(),
                'end_date' => $act->end_date?->toDateString(),
            ];
        }

        $barangays = Barangay::query()
            ->when($barangayIds, fn ($q) => $q->whereIn('id', $barangayIds))
            ->get();

        $choropleth = $this->buildFeatureCollection(
            $barangays,
            function (Barangay $barangay) use ($counts, $sdgBreakdown, $programBreakdown, $extensionDetails) {
                return [
                    'value' => (int) ($counts[$barangay->id] ?? 0),
                    'sdg_breakdown' => $sdgBreakdown[$barangay->id] ?? [],
                    'program_breakdown' => $programBreakdown[$barangay->id] ?? [],
                    'extensions' => $extensionDetails[$barangay->id] ?? [],
                ];
            },
        );

        $activities = ExtensionActivity::query()
            ->with(['program:id,name', 'barangay:id,name', 'sdgs'])
            ->whereNotNull('barangay_id')
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function($qq2) use ($year){
                    $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year);
                });
            }))
            ->when($barangayIds, fn ($q) => $q->whereIn('barangay_id', $barangayIds))
            ->when($sdgIds, fn ($q) => $q->whereHas('sdgs', fn ($sq) => $sq->whereIn('sdgs.id', $sdgIds)))
            ->get();

        // Overall program totals for dialog + city-wide total
        $programTotals = ExtensionActivity::query()
            ->selectRaw('program_id, COUNT(*) as cnt')
            ->whereNotNull('barangay_id')
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($year, fn ($q) => $q->where(function($qq) use ($year){
                $qq->whereYear('start_date', $year)->orWhereYear('end_date', $year)->orWhere(function($qq2) use ($year){
                    $qq2->whereYear('start_date','<=',$year)->whereYear('end_date','>=',$year);
                });
            }))
            ->when($barangayIds, fn ($q) => $q->whereIn('barangay_id', $barangayIds))
            ->when($sdgIds, fn ($q) => $q->whereHas('sdgs', fn ($sq) => $sq->whereIn('sdgs.id', $sdgIds)))
            ->groupBy('program_id')
            ->with('program:id,name')
            ->get()
            ->map(fn($r)=>['program_id'=>$r->program_id,'program_name'=>$r->program?->name ?? 'Unassigned','count'=>(int)$r->cnt])
            ->all();

        $totalCount = array_sum(array_map(fn($r)=>(int)$r['count'], $programTotals)) + $cityWideCount;

        return [
            'city_wide_count' => $cityWideCount,
            'program_totals' => $programTotals,
            'total_count' => $totalCount,
            'choropleth' => $choropleth,
            'markers' => [
                'type' => 'FeatureCollection',
                'features' => $activities->map(fn (ExtensionActivity $activity) => [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        // Activity location is free-text; fall back to barangay centroid
                        'coordinates' => $this->centroid($activity->barangay),
                    ],
                    'properties' => [
                        'id' => $activity->id,
                        'title' => $activity->title,
                        'status' => $activity->status,
                        'progress' => $activity->progress,
                        'start_date' => $activity->start_date?->toDateString(),
                        'end_date' => $activity->end_date?->toDateString(),
                        'beneficiaries' => $activity->beneficiaries,
                        'program' => $activity->program?->name,
                        'barangay' => $activity->barangay?->name,
                        'location' => $activity->location,
                        'sdgs' => $activity->sdgs->map(fn ($s) => [
                            'id' => $s->id,
                            'number' => $s->number,
                            'code' => $s->code,
                            'title' => $s->title,
                            'short_title' => $s->short_title,
                            'color' => $s->color,
                            'icon_url' => $s->icon_url,
                        ])->all(),
                    ],
                ])->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Barangay>  $barangays
     */
    private function buildFeatureCollection($barangays, callable $properties): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => $barangays->map(function (Barangay $barangay) use ($properties) {
                $geometry = $barangay->boundary;
                if ($geometry) {
                    // boundary already stored as GeoJSON geometry object (type + coordinates)
                    // Ensure it is used directly; fallback to centroid only if malformed
                    if (! isset($geometry['type']) || ! isset($geometry['coordinates'])) {
                        $geometry = null;
                    }
                }
                if (! $geometry) {
                    $centroid = $this->centroid($barangay);
                    $geometry = $centroid ? ['type' => 'Point', 'coordinates' => $centroid] : null;
                }

                return [
                    'type' => 'Feature',
                    'id' => $barangay->id,
                    'geometry' => $geometry,
                    'properties' => array_merge([
                        'id' => $barangay->id,
                        'name' => $barangay->name,
                    ], $properties($barangay)),
                ];
            })->filter(fn ($f) => $f['geometry'] !== null)->values()->all(),
        ];
    }

    /**
     * @return array{0: float, 1: float}|null [lng, lat]
     */
    private function centroid(?Barangay $barangay): ?array
    {
        if (! $barangay || ($barangay->latitude === null && $barangay->longitude === null)) {
            return null;
        }

        return [
            (float) ($barangay->longitude ?? 0),
            (float) ($barangay->latitude ?? 0),
        ];
    }
}
