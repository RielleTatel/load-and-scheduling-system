<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRosterRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'grade_level' => ['required', 'in:G7,G8,G9,G10'],
            'full_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:20'],
            'is_magis' => ['nullable', 'boolean'],
            'moderator_name' => ['nullable', 'string', 'max:255'],
            'teacher_partner_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The editable fields; the Admin's edit clears the row's flags. */
    public function rowData(): array
    {
        return [
            'grade_level' => $this->input('grade_level'),
            'full_name' => $this->input('full_name'),
            'name' => $this->input('name'),
            'room' => $this->input('room'),
            'is_magis' => $this->boolean('is_magis'),
            'moderator_name' => $this->input('moderator_name'),
            'teacher_partner_name' => $this->input('teacher_partner_name'),
            'flagged' => false,
            'flags' => [],
        ];
    }
}
