<?php

namespace App\Http\Requests;

use App\Services\ExtensionActivityService;
use Illuminate\Foundation\Http\FormRequest;

class ExtensionActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isCoordinator() || $this->user()->isOfficer();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Use route name to distinguish create vs update — method spoofing via POST with _method=put
        // would make isMethod('post') true for updates (file uploads), breaking validation.
        $isCreating = $this->routeIs('extensions.store');

        return [
            'title' => [$isCreating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'barangay_id' => ['nullable', 'exists:barangays,id'],
            'location' => ['nullable', 'string', 'max:255'],
            // Fragmented days: Day 1..N each with its own date; start/end are derived server-side
            'days' => [$isCreating ? 'required' : 'sometimes', 'array', 'min:1', 'max:60'],
            'days.*' => ['required', 'date', 'distinct'],
            'status' => ['sometimes', 'in:'.implode(',', ExtensionActivityService::STATUSES)],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'faculty_participants' => ['sometimes', 'integer', 'min:0'],
            'student_participants' => ['sometimes', 'integer', 'min:0'],
            'beneficiaries' => ['sometimes', 'integer', 'min:0'],
            'documents' => ['sometimes', 'array'],
            'documents.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,xlsx,xls,doc,docx'],
            'document_activity_date' => ['nullable', 'date'],
            'document_progress' => ['nullable', 'integer', 'between:0,100'],
            'document_caption' => ['nullable', 'string', 'max:255'],
            'sdg_ids' => [$isCreating ? 'required' : 'sometimes', 'array', 'min:1', 'max:17'],
            'sdg_ids.*' => ['integer', 'exists:sdgs,id'],
        ];
    }
}
