<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    /**
     * Upload an encrypted blob to object storage with immutable cache headers.
     * Encrypted blobs are meaningless without the key and never change.
     */
    public function upload(string $path, string $content): void
    {
        $stored = Storage::disk('s3')->put($path, $content, [
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        if ($stored === false) {
            throw new \RuntimeException("Failed to store blob at {$path}.");
        }
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

    /**
     * List objects under a prefix with their size and last-modified timestamp.
     * last_modified is null when the age could not be determined.
     *
     * @return array<int, array{path: string, size: int, last_modified: int|null}>
     */
    public function listObjects(string $prefix = 'photos'): array
    {
        $disk = Storage::disk('s3');
        $objects = [];

        foreach ($disk->allFiles($prefix) as $path) {
            try {
                $modified = $disk->lastModified($path);
            } catch (\Throwable) {
                $modified = false;
            }

            $objects[] = [
                'path' => $path,
                'size' => (int) $disk->size($path),
                'last_modified' => $modified ? (int) $modified : null,
            ];
        }

        return $objects;
    }
}
