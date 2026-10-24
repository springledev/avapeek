<?php

namespace App\Http\Requests;

use App\Rules\PekWrap;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Metadata shared by `photos/upload` and `photos/{uuid}/complete`. Endpoint
 * specific fields (the blobs for upload) come from `extraRules()`.
 */
class PhotoMetadataRequest extends FormRequest
{
    public const ALLOWED_MIMES = 'image/jpeg,image/png,image/webp,image/heic,image/heif';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->metadataRules(), $this->extraRules());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function metadataRules(): array
    {
        return [
            'iv' => ['required', 'string', 'size:32'],
            'iv_thumbnail' => ['required', 'string', 'size:32'],
            'original_mime' => ['required', 'string', 'in:'.self::ALLOWED_MIMES],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:52428800'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'exif_encrypted' => ['nullable', 'string'],
            'iv_exif' => ['nullable', 'string', 'size:32'],
            'taken_at' => ['nullable', 'date'],
            'lqip_encrypted' => ['nullable', 'string'],
            'iv_lqip' => ['nullable', 'string', 'size:32'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'children' => ['required', 'json', new PekWrap],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function extraRules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'],
            'thumbnail' => ['required', 'file', 'max:5120'],
        ];
    }
}
