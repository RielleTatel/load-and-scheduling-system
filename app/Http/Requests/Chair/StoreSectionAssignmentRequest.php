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
                Rule::exists('teachers', 'id')->where('department_id', $this->user()->department_id),
            ],
            'section_id' => ['required', 'exists:sections,id'],
        ];
    }
}
