<?php

namespace App\Http\Requests\Chair;

use Illuminate\Foundation\Http\FormRequest;

class StorePlantillaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isChair();
    }

    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
