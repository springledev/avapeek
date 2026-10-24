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
     * Generate a presigned PUT URL for a direct browser upload of an encrypted blob.
     *
     * The SigV4 presigner does not sign Content-Type or Cache-Control, so they are
     * not enforced; the client sends them as returned so the object gets the
     * immutable cache header. Host is dropped (browsers cannot set it).
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignedPutUrl(string $path, int $minutes = 15): array
    {
        $signed = Storage::disk('s3')->temporaryUploadUrl($path, now()->addMinutes($minutes), [
            'ContentType' => 'application/octet-stream',
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        foreach ($signed['headers'] ?? [] as $name => $value) {
            if (strtolower((string) $name) === 'host') {
                continue;
            }
            foreach (array_keys($headers) as $existing) {
                if (strtolower($existing) === strtolower((string) $name)) {
                    unset($headers[$existing]);
                }
            }
            $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return ['url' => $signed['url'], 'headers' => $headers];
    }

    /**
     * Size in bytes of a stored object (HEAD), or null when it does not exist.
     */
    public function objectSize(string $path): ?int
    {
        try {
            $size = Storage::disk('s3')->size($path);
        } catch (\Throwable) {
            return null;
        }

        return $size === false ? null : (int) $size;
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
