<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $section = $this->route('section');

        return [
            'grade_level' => ['required', Rule::in(['G7', 'G8', 'G9', 'G10'])],
            // Section names must stay unique school-wide: plantilla extraction
            // recovers a section's grade from its name alone.
            'name' => ['required', 'string', 'max:120', Rule::unique('sections', 'name')->ignore($section)],
            'full_name' => ['nullable', 'string', 'max:200'],
            'room' => ['nullable', 'string', 'max:10'],
            'is_magis' => ['boolean'],
            'moderator_name' => ['nullable', 'string', 'max:150'],
            'teacher_partner_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_magis' => (bool) $this->input('is_magis')]);
    }
}
