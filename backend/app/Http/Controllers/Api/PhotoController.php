<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Photo;
use App\Models\Tag;
use App\Services\QuotaService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function __construct(
        protected StorageService $storageService,
        protected QuotaService $quotaService,
    ) {}

    /**
     * Upload an encrypted photo with thumbnails.
     *
     * Receives 4 encrypted blobs (original + 3 thumbnails) + metadata.
     * No DEK is transmitted to the server.
     */
    public function upload(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'iv' => ['required', 'string', 'size:32'],
            'thumbnail_sm' => ['required', 'file'],
            'iv_sm' => ['required', 'string', 'size:32'],
            'thumbnail_md' => ['required', 'file'],
            'iv_md' => ['required', 'string', 'size:32'],
            'thumbnail_lg' => ['required', 'file'],
            'iv_lg' => ['required', 'string', 'size:32'],
            'mime_type' => ['required', 'string', 'max:50'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'exif_encrypted' => ['nullable', 'string'],
            'iv_exif' => ['nullable', 'string', 'size:32'],
            'taken_at' => ['nullable', 'date'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
        ]);

        $fileObj = $request->file('file');
        $smObj = $request->file('thumbnail_sm');
        $mdObj = $request->file('thumbnail_md');
        $lgObj = $request->file('thumbnail_lg');

        $totalBytes = $fileObj->getSize()
            + $smObj->getSize()
            + $mdObj->getSize()
            + $lgObj->getSize();

        if (! $this->quotaService->checkQuota($child, $totalBytes)) {
            return response()->json([
                'message' => 'Storage quota exceeded for this child.',
            ], 413);
        }

        $uuid = (string) str()->uuid();

        $originalPath = "{$child->id}/{$uuid}.webp.enc";
        $smPath = "{$child->id}/{$uuid}_sm.webp.enc";
        $mdPath = "{$child->id}/{$uuid}_md.webp.enc";
        $lgPath = "{$child->id}/{$uuid}_lg.webp.enc";

        $this->storageService->upload($originalPath, file_get_contents($fileObj->getPathname()));
        $this->storageService->upload($smPath, file_get_contents($smObj->getPathname()));
        $this->storageService->upload($mdPath, file_get_contents($mdObj->getPathname()));
        $this->storageService->upload($lgPath, file_get_contents($lgObj->getPathname()));

        $photo = Photo::create([
            'child_id' => $child->id,
            'uploaded_by' => $request->user()->id,
            'original_path' => $originalPath,
            'thumbnail_sm_path' => $smPath,
            'thumbnail_md_path' => $mdPath,
            'thumbnail_lg_path' => $lgPath,
            'iv_original' => $validated['iv'],
            'iv_sm' => $validated['iv_sm'],
            'iv_md' => $validated['iv_md'],
            'iv_lg' => $validated['iv_lg'],
            'mime_type' => $validated['mime_type'],
            'size_bytes' => $validated['size_bytes'],
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'exif_encrypted' => $validated['exif_encrypted'] ?? null,
            'iv_exif' => $validated['iv_exif'] ?? null,
            'thumbnail_status' => 'ready',
            'taken_at' => $validated['taken_at'] ?? null,
        ]);

        if (! empty($validated['tags'])) {
            $tagIds = [];
            foreach ($validated['tags'] as $tagName) {
                $tag = Tag::firstOrCreate(
                    ['child_id' => $child->id, 'name' => $tagName],
                    ['created_by' => $request->user()->id],
                );
                $tagIds[] = $tag->id;
            }
            $photo->tags()->sync($tagIds);
        }

        $this->quotaService->addUsage($child, $totalBytes);

        $photo->load('tags');

        return response()->json([
            'data' => $this->formatPhoto($photo),
        ], 201);
    }

    /**
     * List photos in a child's feed.
     */
    public function index(Request $request, Child $child): JsonResponse
    {
        $query = Photo::where('child_id', $child->id)
            ->with('tags')
            ->with('uploader:id,display_name');

        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', function ($q) use ($tag) {
                $q->where('name', $tag);
            });
        }

        if ($month = $request->query('month')) {
            $start = "{$month}-01 00:00:00";
            $end = date('Y-m-t 23:59:59', strtotime($start));
            $query->whereBetween('taken_at', [$start, $end]);
        }

        $photos = $query->orderBy('taken_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json([
            'data' => $photos->getCollection()->map(fn (Photo $photo) => $this->formatPhoto($photo)),
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'per_page' => $photos->perPage(),
                'total' => $photos->total(),
            ],
        ]);
    }

    /**
     * Show a single photo with presigned URL for the original.
     */
    public function show(Child $child, Photo $photo): JsonResponse
    {
        $photo->load('tags');
        $presignedUrl = $this->storageService->presignedUrl($photo->original_path, 10);

        return response()->json([
            'data' => array_merge($this->formatPhoto($photo), [
                'presigned_url' => $presignedUrl,
            ]),
        ]);
    }

    /**
     * Get a presigned URL for a thumbnail at the requested size.
     */
    public function thumbnail(Request $request, Child $child, Photo $photo, string $size): JsonResponse
    {
        $path = match ($size) {
            'sm' => $photo->thumbnail_sm_path,
            'md' => $photo->thumbnail_md_path,
            'lg' => $photo->thumbnail_lg_path,
            default => null,
        };

        if ($path === null) {
            return response()->json(['message' => 'Invalid thumbnail size. Use sm, md, or lg.'], 422);
        }

        $url = $this->storageService->presignedUrl($path, 10);

        return response()->json([
            'data' => [
                'size' => $size,
                'presigned_url' => $url,
            ],
        ]);
    }

    /**
     * Delete a photo and all its encrypted blobs from storage.
     *
     * Restricted to owner/admin or the original uploader.
     */
    public function destroy(Request $request, Child $child, Photo $photo): JsonResponse
    {
        $user = $request->user();
        $role = $user->children()
            ->where('child_id', $child->id)
            ->first()?->pivot->role;

        if (! in_array($role, ['owner', 'admin'], true) && $photo->uploaded_by !== $user->id) {
            return response()->json([
                'message' => 'You do not have permission to delete this photo.',
            ], 403);
        }

        $paths = [
            $photo->original_path,
            $photo->thumbnail_sm_path,
            $photo->thumbnail_md_path,
            $photo->thumbnail_lg_path,
        ];

        $totalBytes = 0;
        foreach ($paths as $path) {
            $size = Storage::disk('s3')->size($path);
            if ($size !== false) {
                $totalBytes += $size;
            }
        }

        $this->storageService->deleteMultiple($paths);

        $this->quotaService->removeUsage($child, $totalBytes);

        $photo->delete();

        return response()->json(null, 204);
    }

    private function formatPhoto(Photo $photo): array
    {
        return [
            'id' => $photo->id,
            'child_id' => $photo->child_id,
            'uploaded_by' => $photo->uploaded_by,
            'uploader_name' => $photo->uploader?->display_name,
            'original_path' => $photo->original_path,
            'thumbnail_sm_path' => $photo->thumbnail_sm_path,
            'thumbnail_md_path' => $photo->thumbnail_md_path,
            'thumbnail_lg_path' => $photo->thumbnail_lg_path,
            'iv_original' => $photo->iv_original,
            'iv_sm' => $photo->iv_sm,
            'iv_md' => $photo->iv_md,
            'iv_lg' => $photo->iv_lg,
            'mime_type' => $photo->mime_type,
            'size_bytes' => $photo->size_bytes,
            'width' => $photo->width,
            'height' => $photo->height,
            'exif_encrypted' => $photo->exif_encrypted,
            'iv_exif' => $photo->iv_exif,
            'thumbnail_status' => $photo->thumbnail_status,
            'taken_at' => $photo->taken_at?->toISOString(),
            'created_at' => $photo->created_at?->toISOString(),
            'tags' => $photo->tags?->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ]),
        ];
    }
}
