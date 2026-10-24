<?php

namespace App\Http\Requests;

use App\Rules\Base64Bytes;
use Illuminate\Foundation\Http\FormRequest;

class SharePhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A wrap is an AES-GCM-encrypted 32-byte key (48 bytes) and a 12-byte IV, both base64.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'child_id' => ['required', 'integer'],
            'photos' => ['required', 'array', 'min:1', 'max:'.BulkDeletePhotosRequest::MAX_PHOTOS],
            'photos.*' => ['required', 'array'],
            'photos.*.photo_id' => ['required', 'integer', 'distinct'],
            'photos.*.encrypted_pek' => ['required', 'string', new Base64Bytes(48)],
            'photos.*.iv_pek' => ['required', 'string', new Base64Bytes(12)],
        ];
    }
}
