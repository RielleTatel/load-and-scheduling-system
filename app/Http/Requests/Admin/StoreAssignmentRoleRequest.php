<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_honorarium' => $this->boolean('is_honorarium')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('other_assignment_roles', 'name')->ignore($this->route('role'))],
            'is_honorarium' => ['boolean'],
            // Honorarium-only roles carry no equivalent hours — the two are mutually exclusive.
            'equivalent_hours' => ['nullable', 'numeric', 'min:0', 'prohibited_if:is_honorarium,true'],
        ];
    }
}
