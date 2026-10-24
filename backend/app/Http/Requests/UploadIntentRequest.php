<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'original_bytes' => ['required', 'integer', 'min:1', 'max:52428800'],
            'thumbnail_bytes' => ['required', 'integer', 'min:1', 'max:5242880'],
            'original_mime' => ['required', 'string', 'in:'.PhotoMetadataRequest::ALLOWED_MIMES],
        ];
    }
}
