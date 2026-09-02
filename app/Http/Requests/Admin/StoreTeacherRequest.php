<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmploymentStatus;
use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'employment_status' => ['nullable', Rule::in(array_column(EmploymentStatus::cases(), 'value'))],
        ];
    }

    /**
     * Identity is the normalized name, not the typed string — otherwise
     * "Frizie B. Dealagdon" and "Fritzie Dealagdon" become two people.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $existing = Teacher::where('normalized_name', Teacher::normalize($this->input('full_name')))
                    ->when($this->route('teacher'), fn ($q) => $q->whereKeyNot($this->route('teacher')->getKey()))
                    ->first();

                if ($existing) {
                    $validator->errors()->add('full_name', "\"{$existing->full_name}\" is already in the directory.");
                }
            },
        ];
    }
}
