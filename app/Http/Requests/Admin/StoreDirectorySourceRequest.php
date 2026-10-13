<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDirectorySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:255'],
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            // Unknown coverage is partial: only a declared-complete list can
            // say someone is missing.
            'completeness' => ['required', Rule::in(['complete', 'partial'])],
            'department_id' => ['nullable', 'exists:departments,id'],
            'names' => ['required', 'string', 'max:20000'],
        ];
    }
}
