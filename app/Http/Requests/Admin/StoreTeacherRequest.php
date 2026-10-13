<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmploymentStatus;
use App\Models\Teacher;
use App\Services\Teachers\TeacherIdentityService;
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
            'confirm_distinct' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Identity is the normalized name, not the typed string — otherwise
     * "Frizie B. Dealagdon" and "Fritzie Dealagdon" become two people. An
     * exact name or approved alias is refused; a look-alike needs the Admin
     * to say it is someone else.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $identities = app(TeacherIdentityService::class);
                $self = $this->route('teacher');
                $name = (string) $this->input('full_name');

                $existing = $identities->exactCandidates(Teacher::normalize($name))
                    ->reject(fn (Teacher $t) => $self && $t->is($self))->first();

                if ($existing) {
                    $validator->errors()->add('full_name', "\"{$existing->full_name}\" is already in the directory.");

                    return;
                }

                $renaming = ! $self || Teacher::normalize($name) !== $self->normalized_name;
                $similar = $renaming ? $identities->similarCandidates($name, $self?->id) : collect();

                if ($similar->isNotEmpty() && ! $this->boolean('confirm_distinct')) {
                    $validator->errors()->add('full_name', 'This resembles ' . $similar->pluck('full_name')->map(fn ($n) => "\"{$n}\"")->implode(', ')
                        . '. If this is a different person, tick "This is a different person" and save again; if it is the same person, edit that record instead.');
                }
            },
        ];
    }
}
