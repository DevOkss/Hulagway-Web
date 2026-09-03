<?php

namespace App\Exports;

use App\Models\Survey;
use Maatwebsite\Excel\Concerns\FromArray;

class SurveyResponsesExport implements FromArray
{
    public function __construct(private readonly Survey $survey) {}

    public function array(): array
    {
        $questions = $this->survey->questions()->orderBy('order')->get();
        $questionTexts = $questions->pluck('question_text');

        $isHousehold = ($this->survey->type ?? 'generic') === 'household';

        // For household surveys, include household columns in headings
        $householdHeadings = [];
        if ($isHousehold) {
            $householdHeadings = ['Household Head', 'Purok', 'Contact No.', 'Live-in', 'Live-in Years', 'Live-in Reason', 'Members Count'];
        }

        $rows = [$this->headings($questionTexts, $householdHeadings)];

        foreach ($this->survey->responses()->with(['answers', 'barangay:id,name', 'household.members'])->get() as $response) {
            $answerMap = $response->answers->pluck('answer', 'survey_question_id');

            $row = [
                $response->submitted_at?->format('Y-m-d H:i'),
                $response->barangay?->name,
                $response->source,
            ];

            if ($isHousehold) {
                $household = $response->household;
                $row[] = $household?->head_name ?? '';
                $row[] = $response->purok ?? $household?->purok ?? '';
                $row[] = $household?->contact_no ?? '';
                $row[] = $household?->live_in_status ?? '';
                $row[] = $household?->live_in_years ?? '';
                $row[] = $household?->live_in_reason ?? '';
                $row[] = $household?->members->count() ?? 0;
            }

            foreach ($questions as $question) {
                $answer = $answerMap->get($question->id);
                $decoded = json_decode((string) $answer, true);
                $row[] = is_array($decoded) ? implode(', ', $decoded) : ($answer ?? '');
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function headings($questionTexts, array $extraHeadings = []): array
    {
        return ['Submitted', 'Barangay', 'Source', ...$extraHeadings, ...$questionTexts->all()];
    }
}
