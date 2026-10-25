<?php

namespace App\Http\Requests;

/**
 * Same metadata as the proxy upload, minus the blobs (already in storage).
 */
class CompleteUploadRequest extends PhotoMetadataRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function extraRules(): array
    {
        return [];
    }
}
