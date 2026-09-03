<?php

namespace App\Services;

use App\Models\Barangay;
use App\Models\ExtensionActivity;
use App\Models\Program;
use App\Models\Survey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Activities filtered by program/barangay/status/year.
     * $status may be single value or comma list; year filters by start/end year overlap.
     */
    public function activities(?int $programId = null, ?int $barangayId = null, ?string $status = null, ?int $year = null, ?string $from = null, ?string $to = null): Collection
    {
        // For reports: default to planned/ongoing/completed (cancelled hidden unless explicitly filtered)
        return ExtensionActivity::query()
            ->with(['program.institute:id,name', 'barangay:id,name', 'sdgs'])
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->when($barangayId, fn ($q) => $q->where('barangay_id', $barangayId))
            ->when($status, function ($q) use ($status) {
                if (str_contains($status, ',')) {
                    $q->whereIn('status', explode(',', $status));
                } else {
                    $q->where('status', $status);
                }
            }, function ($q) {
                $q->whereIn('status', [ExtensionActivity::STATUS_PLANNED, ExtensionActivity::STATUS_ONGOING, ExtensionActivity::STATUS_COMPLETED]);
            })
            ->when($year, fn ($q) => $q->where(function ($qq) use ($year) {
                $qq->whereYear('start_date', $year)
                    ->orWhereYear('end_date', $year)
                    ->orWhere(function ($qq2) use ($year) {
                        $qq2->whereYear('start_date', '<=', $year)->whereYear('end_date', '>=', $year);
                    });
            }))
            ->when($from, fn ($q) => $q->whereDate('start_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('start_date', '<=', $to))
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Summary rows for the survey responses report.
     */
    public function surveySummary(Survey $survey): array
    {
        $total = $survey->responses()->count();

        return [
            'survey' => ['id' => $survey->id, 'title' => $survey->title],
            'total_responses' => $total,
            'by_barangay' => $survey->responses()
                ->join('barangays', 'barangays.id', '=', 'survey_responses.barangay_id')
                ->selectRaw('barangays.name, COUNT(*) as total')
                ->groupBy('barangays.name')
                ->pluck('total', 'name'),
            'by_source' => DB::table('survey_responses')
                ->where('survey_id', $survey->id)
                ->groupBy('source')
                ->selectRaw('source, COUNT(*) as total')
                ->pluck('total', 'source'),
            'hulagway' => $this->hulagwaySummaryForSurvey($survey),
        ];
    }

    public function hulagwaySummaryForSurvey(Survey $survey): array
    {
        $metrics = ['seniors_90','bedridden_cat2','mentally_cat2','pwd_cat2','osy','live_in','poor_cat2','pregnant'];
        $result = [];
        // Per-member from household_members (new spec) with fallback to legacy survey_answers
        $memberCodeMap = [
            'pwd_cat2' => ['field'=>'is_pwd','value'=>['Yes - Makalakaw pa','Yes - Di na ka lakaw']],
            'mentally_cat2' => ['field'=>'is_mentally_challenged','value'=>['Yes - dili problema sa katilingban','Yes - hasol sa katilingban']],
            'osy' => ['field'=>'is_osy','value'=>1],
            'pregnant' => ['field'=>'is_pregnant','value'=>1],
            'bedridden_cat2' => ['field'=>'bedridden_status','value'=>['Yes - Mabakod pa','Yes - Di na kabakod']],
        ];
        foreach ($metrics as $code) {
            $like = $code === 'poor_cat2' ? 'Category 2%' : 'Yes';
            if ($code === 'seniors_90') {
                $result[$code] = \App\Models\HouseholdMember::whereHas('household', fn($q)=>$q->whereIn('id', $survey->responses()->pluck('household_id')))->where('age','>=',90)->count();
                if ($result[$code] === 0) {
                    $result[$code] = DB::table('survey_answers')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code',$code)->where('survey_answers.answer','like','Yes')
                        ->whereNotNull('survey_answers.household_member_id')
                        ->whereIn('survey_answers.survey_response_id', $survey->responses()->pluck('id'))->distinct()->count('survey_answers.household_member_id');
                }
            } elseif (isset($memberCodeMap[$code])) {
                $map = $memberCodeMap[$code];
                $q = \App\Models\HouseholdMember::whereHas('household', fn($hq)=>$hq->whereIn('id', $survey->responses()->pluck('household_id')));
                if (is_array($map['value'])) $q->whereIn($map['field'], $map['value']); else $q->where($map['field'], $map['value']);
                $count = $q->count();
                if ($count === 0) {
                    $count = DB::table('survey_answers')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code',$code)->where('survey_answers.answer','like','Yes')
                        ->whereNotNull('survey_answers.household_member_id')
                        ->whereIn('survey_answers.survey_response_id', $survey->responses()->pluck('id'))->distinct()->count('survey_answers.household_member_id');
                }
                $result[$code] = $count;
            } elseif ($code === 'live_in') {
                $count = \App\Models\Household::whereIn('id', $survey->responses()->pluck('household_id'))->where('live_in_status','Yes')->count();
                if ($count === 0) {
                    $count = DB::table('survey_answers')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code','live_in')->where('survey_answers.answer','like','Yes')
                        ->whereIn('survey_answers.survey_response_id', $survey->responses()->pluck('id'))->distinct()->count('survey_answers.survey_response_id');
                }
                $result[$code] = $count;
            } elseif ($code === 'poor_cat2') {
                $result[$code] = DB::table('survey_answers')
                    ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                    ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                    ->where('survey_questions.code','poor_cat2')->where('survey_answers.answer','like','Category 2%')
                    ->whereIn('survey_answers.survey_response_id', $survey->responses()->pluck('id'))->distinct()->count('survey_responses.household_id');
            } else {
                $result[$code] = DB::table('survey_answers')
                    ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                    ->where('survey_questions.code',$code)->where('survey_answers.answer','like',$like)
                    ->whereNotNull('survey_answers.household_member_id')
                    ->whereIn('survey_answers.survey_response_id', $survey->responses()->pluck('id'))->distinct()->count('survey_answers.household_member_id');
            }
        }
        return $result;
    }

    public function hulagwaySummary(?string $from = null, ?string $to = null, ?int $year = null): array
    {
        // Year filter takes precedence over from/to
        $yearFilter = function ($q) use ($year, $from, $to) {
            if ($year) return $q->whereYear('created_at', $year);
            return $q->when($from, fn($qq)=>$qq->whereDate('created_at','>=',$from))->when($to, fn($qq)=>$qq->whereDate('created_at','<=',$to));
        };
        $yearFilterResponses = function ($q) use ($year, $from, $to) {
            if ($year) return $q->whereYear('submitted_at', $year);
            return $q->when($from, fn($qq)=>$qq->whereDate('submitted_at','>=',$from))->when($to, fn($qq)=>$qq->whereDate('submitted_at','<=',$to));
        };
        $yearFilterAnswers = function ($q) use ($year, $from, $to) {
            if ($year) return $q->whereYear('survey_answers.created_at', $year);
            return $q->when($from, fn($qq)=>$qq->whereDate('survey_answers.created_at','>=',$from))->when($to, fn($qq)=>$qq->whereDate('survey_answers.created_at','<=',$to));
        };
        $metrics = ['seniors_90','bedridden_cat2','mentally_cat2','pwd_cat2','osy','live_in','poor_cat2','pregnant'];
        $result = [];
        $memberCodeMap = [
            'pwd_cat2' => ['field'=>'is_pwd','value'=>['Yes - Makalakaw pa','Yes - Di na ka lakaw']],
            'mentally_cat2' => ['field'=>'is_mentally_challenged','value'=>['Yes - dili problema sa katilingban','Yes - hasol sa katilingban']],
            'osy' => ['field'=>'is_osy','value'=>1],
            'pregnant' => ['field'=>'is_pregnant','value'=>1],
            'bedridden_cat2' => ['field'=>'bedridden_status','value'=>['Yes - Mabakod pa','Yes - Di na kabakod']],
        ];
        foreach ($metrics as $code) {
            if ($code === 'seniors_90') {
                $result[$code] = \App\Models\HouseholdMember::where('age','>=',90)->tap($yearFilter)->count();
                if ($result[$code] === 0) {
                    $result[$code] = DB::table('survey_answers')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code',$code)->where('survey_answers.answer','like','Yes')
                        ->whereNotNull('survey_answers.household_member_id')
                        ->tap($yearFilterAnswers)->distinct()->count('survey_answers.household_member_id');
                }
            } elseif (isset($memberCodeMap[$code])) {
                $map = $memberCodeMap[$code];
                $q = \App\Models\HouseholdMember::query()->tap($yearFilter);
                if (is_array($map['value'])) $q->whereIn($map['field'], $map['value']); else $q->where($map['field'], $map['value']);
                $count = $q->count();
                if ($count === 0) {
                    $count = DB::table('survey_answers')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code',$code)->where('survey_answers.answer','like','Yes')
                        ->whereNotNull('survey_answers.household_member_id')
                        ->tap($yearFilterAnswers)->distinct()->count('survey_answers.household_member_id');
                }
                $result[$code] = $count;
            } elseif ($code === 'live_in') {
                $count = \App\Models\Household::where('live_in_status','Yes')->tap($yearFilter)->count();
                if ($count === 0) {
                    $count = DB::table('survey_answers')
                        ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                        ->where('survey_questions.code','live_in')->where('survey_answers.answer','like','Yes')
                        ->tap($yearFilterAnswers)->distinct()->count('survey_answers.survey_response_id');
                }
                $result[$code] = $count;
            } elseif ($code === 'poor_cat2') {
                $result[$code] = DB::table('survey_answers')
                    ->join('survey_responses','survey_responses.id','=','survey_answers.survey_response_id')
                    ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                    ->where('survey_questions.code','poor_cat2')->where('survey_answers.answer','like','Category 2%')
                    ->tap($yearFilterAnswers)->distinct()->count('survey_responses.household_id');
            } else {
                $result[$code] = DB::table('survey_answers')
                    ->join('survey_questions','survey_questions.id','=','survey_answers.survey_question_id')
                    ->where('survey_questions.code',$code)->where('survey_answers.answer','like','Yes')
                    ->whereNotNull('survey_answers.household_member_id')
                    ->tap($yearFilterAnswers)->distinct()->count('survey_answers.household_member_id');
            }
        }
        $result['households'] = \App\Models\Household::tap($yearFilter)->count();
        $result['total_responses'] = \App\Models\SurveyResponse::tap($yearFilterResponses)->count();
        return $result;
    }

    public function filterOptions(): array
    {
        return [
            'programs' => Program::orderBy('name')->get(['id', 'name']),
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
        ];
    }
}
