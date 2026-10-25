<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class BulkTagPhotosRequest extends FormRequest
{
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
            'assignments' => ['required', 'array', 'min:1', 'max:'.BulkDeletePhotosRequest::MAX_PHOTOS],
            'assignments.*' => ['required', 'array'],
            'assignments.*.photo_id' => ['required', 'integer', 'distinct'],
            'assignments.*.tag_ids' => [
                'required', 'array', 'min:1', 'max:20',
                // `distinct` on a nested wildcard compares across all photos, so check per photo.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && count($value) !== count(array_unique($value))) {
                        $fail('The :attribute field has duplicate values.');
                    }
                },
            ],
            'assignments.*.tag_ids.*' => ['required', 'integer'],
        ];
    }
}
