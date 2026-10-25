<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The server cannot see tag names, so only the ciphertext envelope is validated.
 */
class StoreTagRequest extends FormRequest
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
            'name_encrypted' => ['required', 'string', 'max:512', 'regex:/^[A-Za-z0-9+\/]+={0,2}$/'],
            'iv_name' => ['required', 'string', 'size:32', 'regex:/^[0-9a-f]{32}$/i'],
        ];
    }
}
