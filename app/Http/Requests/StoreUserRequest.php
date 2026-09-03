<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOfficer();
    }

    public function rules(): array
    {
        $coordinatorRoles = [Role::COORDINATOR, Role::FIELD_PERSONNEL];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
            'institute_id' => ['nullable', 'exists:institutes,id'],
            'program_id' => [
                Rule::requiredIf(fn () => in_array(
                    Role::find($this->input('role_id'))?->name,
                    [Role::COORDINATOR],
                )),
                'nullable',
                Rule::exists('programs', 'id')->where(
                    fn ($q) => $this->filled('institute_id')
                        ? $q->where('institute_id', $this->input('institute_id'))
                        : $q,
                ),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        // Coordinators inherit the institute of their program
        if (! empty($data['program_id'])) {
            $data['institute_id'] = Program::find($data['program_id'])?->institute_id;
        }

        return $data;
    }
}
