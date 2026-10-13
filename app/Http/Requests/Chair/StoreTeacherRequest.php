<?php

namespace App\Http\Requests\Chair;

use App\Enums\EmploymentStatus;
use App\Models\Teacher;
use App\Services\Teachers\TeacherIdentityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isChair();
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'employment_status' => ['required', new Enum(EmploymentStatus::class)],
        ];
    }

    /**
     * Someone already in the directory under this exact name or an approved
     * alias — in any department — is not added again. A Chair cannot see other
     * departments, so the message says only that an Admin must decide.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $self = $this->route('teacher');
                $existing = app(TeacherIdentityService::class)
                    ->exactCandidates(Teacher::normalize((string) $this->input('full_name')))
                    ->reject(fn (Teacher $t) => $self && $t->is($self))->first();

                if (! $existing) {
                    return;
                }

                $validator->errors()->add('full_name', $existing->department_id === $this->user()->department_id
                    ? "\"{$existing->full_name}\" is already on your roster."
                    : 'A teacher with this name is already in the school directory. Ask the Admin to confirm who this is.');
            },
        ];
    }
}
