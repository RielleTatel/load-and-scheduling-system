<?php

namespace App\Http\Requests\Chair;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isChair();
    }

    public function rules(): array
    {
        return [
            // The teacher must belong to the requesting chair's own department.
            'teacher_id' => [
                'required',
                // A merged record is history; its loads live on the survivor.
                Rule::exists('teachers', 'id')->where('department_id', $this->user()->department_id)->whereNull('merged_into_teacher_id'),
            ],
            'section_id' => ['required', 'exists:sections,id'],
        ];
    }
}
