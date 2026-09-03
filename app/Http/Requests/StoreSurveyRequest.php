<?php

namespace App\Http\Requests;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOfficer();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:'.implode(',', \App\Models\Survey::TYPES)],
            'include_barangay' => ['nullable', 'boolean'],
            'barangay_id' => ['nullable', 'exists:barangays,id'],
            'questions' => ['sometimes', 'array'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.question_text' => ['required', 'string', 'max:1000'],
            'questions.*.type' => ['required', 'in:'.implode(',', SurveyQuestion::TYPES)],
            'questions.*.is_required' => ['boolean'],
            'questions.*.order' => ['integer', 'min:0'],
            'questions.*.code' => ['nullable', 'string', 'max:50'],
            'questions.*.data_scope' => ['nullable', 'in:'.implode(',', SurveyQuestion::DATA_SCOPES)],
            'questions.*.map_enabled' => ['nullable', 'boolean'],
            'questions.*.options' => ['nullable', 'array'],
        ];
    }
}
