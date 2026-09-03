<?php

namespace App\Http\Requests\Chair;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExtractionRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isChair();
    }

    public function rules(): array
    {
        return [
            'teacher_name' => ['nullable', 'string', 'max:500'],
            'employment_status' => ['nullable', 'string', 'max:500'],
            'sections' => ['nullable', 'string', 'max:500'],
            'cm' => ['nullable', 'string', 'max:500'],
            'hc' => ['nullable', 'string', 'max:500'],
            'service_load' => ['nullable', 'string', 'max:500'],
            'other_assignment' => ['nullable', 'string', 'max:500'],
            'stated_totals' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The staging fields as a plain array, with missing keys as null.
     * stated_totals is the extractor's reference string, resubmitted by a
     * hidden field on the review form - not an editable input.
     */
    public function rowData(): array
    {
        return [
            'teacher_name' => $this->input('teacher_name'),
            'employment_status' => $this->input('employment_status'),
            'sections' => $this->input('sections'),
            'cm' => $this->input('cm'),
            'hc' => $this->input('hc'),
            'service_load' => $this->input('service_load'),
            'other_assignment' => $this->input('other_assignment'),
            'stated_totals' => $this->input('stated_totals'),
        ];
    }
}
