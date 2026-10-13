<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'basis' => ['required', Rule::in(['plantilla', 'directory', 'other'])],
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
