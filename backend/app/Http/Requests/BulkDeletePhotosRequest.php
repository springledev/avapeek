<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeletePhotosRequest extends FormRequest
{
    public const MAX_PHOTOS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'photo_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS],
            'photo_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
