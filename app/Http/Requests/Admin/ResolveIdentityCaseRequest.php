<?php

namespace App\Http\Requests\Admin;

use App\Enums\IdentityDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveIdentityCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(array_column(array_filter(
                IdentityDecision::cases(), fn ($d) => $d !== IdentityDecision::Superseded,
            ), 'value'))],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            // The decision must say why: it is the evidence later reviewers read.
            'note' => ['required', 'string', 'max:2000'],
            'version' => ['required', 'string'],
        ];
    }
}
