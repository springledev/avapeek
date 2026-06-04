<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    /**
     * Upload an encrypted blob to object storage.
     */
    public function upload(string $path, string $content): void
    {
        Storage::disk('s3')->put($path, $content);
    }

    /**
     * Download an encrypted blob from object storage.
     */
    public function download(string $path): string
    {
        return Storage::disk('s3')->get($path);
    }

    /**
     * Delete a single blob from object storage.
     */
    public function delete(string $path): void
    {
        Storage::disk('s3')->delete($path);
    }

    /**
     * Delete multiple blobs from object storage.
     */
    public function deleteMultiple(array $paths): void
    {
        Storage::disk('s3')->delete($paths);
    }

    /**
     * Generate a presigned URL for temporary access to an encrypted blob.
     */
    public function presignedUrl(string $path, int $minutes = 5): string
    {
        return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes($minutes));
    }
}
