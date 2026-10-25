<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsPhotos;
use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Tag;
use App\Policies\PhotoPolicy;
use App\Services\QuotaService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PhotoController extends Controller
{
    use FormatsPhotos;

    public function __construct(
        protected StorageService $storageService,
        protected QuotaService $quotaService,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'iv' => ['required', 'string', 'size:32'],
            'thumbnail' => ['required', 'file', 'max:5120'],
            'iv_thumbnail' => ['required', 'string', 'size:32'],
            'original_mime' => ['required', 'string', 'in:image/jpeg,image/png,image/webp,image/heic,image/heif'],
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
            'children' => ['required', 'json'],
        ]);

        $childrenData = json_decode($validated['children'], true);
        if (empty($childrenData)) {
            return response()->json(['message' => 'At least one child is required.'], 422);
        }

        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id')->toArray();

        $childIds = array_keys($childrenData);
        $unknownIds = array_diff($childIds, $accessibleChildIds);
        if (! empty($unknownIds)) {
            return response()->json(['message' => 'Invalid child IDs: '.implode(', ', $unknownIds)], 422);
        }

        $fileObj = $request->file('file');
        $thumbObj = $request->file('thumbnail');

        if ($fileObj->getSize() > 52428800) {
            return response()->json(['message' => 'Uploaded file exceeds the maximum allowed size of 50 MB.'], 413);
        }

        if ($thumbObj->getSize() > 5242880) {
            return response()->json(['message' => 'Uploaded thumbnail exceeds the maximum allowed size of 5 MB.'], 413);
        }

        $totalBytes = $fileObj->getSize() + $thumbObj->getSize();

        if (! $this->quotaService->checkQuota($totalBytes)) {
            return response()->json(['message' => 'Storage quota exceeded.'], 413);
        }

        $uuid = (string) str()->uuid();
        $ext = match ($validated['original_mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default => 'bin',
        };

        $originalPath = "photos/{$uuid}.{$ext}.enc";
        $thumbPath = "photos/{$uuid}_thumb.webp.enc";

        $uploadedPaths = [];

        try {
            $this->storageService->upload($originalPath, file_get_contents($fileObj->getPathname()));
            $uploadedPaths[] = $originalPath;
            $this->storageService->upload($thumbPath, file_get_contents($thumbObj->getPathname()));
            $uploadedPaths[] = $thumbPath;

            $photo = DB::transaction(function () use ($validated, $childrenData, $user, $uuid, $originalPath, $thumbPath, $totalBytes): Photo {
                $photo = Photo::create([
                    'uuid' => $uuid,
                    'uploaded_by' => $user->id,
                    'original_path' => $originalPath,
                    'thumbnail_path' => $thumbPath,
                    'lqip_encrypted' => $validated['lqip_encrypted'] ?? null,
                    'iv_lqip' => $validated['iv_lqip'] ?? null,
                    'iv_original' => $validated['iv'],
                    'iv_thumbnail' => $validated['iv_thumbnail'],
                    'original_mime' => $validated['original_mime'],
                    'size_bytes' => $validated['size_bytes'],
                    'stored_bytes' => $totalBytes,
                    'width' => $validated['width'] ?? null,
                    'height' => $validated['height'] ?? null,
                    'exif_encrypted' => $validated['exif_encrypted'] ?? null,
                    'iv_exif' => $validated['iv_exif'] ?? null,
                    'taken_at' => $validated['taken_at'] ?? null,
                ]);

                foreach ($childrenData as $childId => $wrap) {
                    $photo->children()->attach($childId, [
                        'encrypted_pek' => $wrap['encrypted_pek'],
                        'iv_pek' => $wrap['iv_pek'],
                    ]);
                }

                if (! empty($validated['tags'])) {
                    $tagIds = [];
                    foreach ($validated['tags'] as $tagName) {
                        $tag = Tag::visibleTo($user)->where('name', $tagName)->orderBy('tags.id')->first()
                            ?? Tag::create(['name' => $tagName, 'created_by' => $user->id]);
                        $tagIds[] = $tag->id;
                    }
                    $photo->tags()->sync($tagIds);
                }

                $this->quotaService->addUsage($totalBytes);

                return $photo;
            });
        } catch (\Throwable $e) {
            $this->cleanupBlobs([$originalPath, $thumbPath]);

            throw $e;
        }

        $photo->load(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }]);
        $photo->loadCount('comments');

        return response()->json([
            'data' => $this->formatPhoto($photo),
        ], 201);
    }

    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id');

        $query = Photo::visibleTo($user)->with(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }])->withCount('comments');

        if ($childrenFilter = $request->query('children')) {
            $filterIds = array_map('intval', explode(',', $childrenFilter));
            $validIds = array_intersect($filterIds, $accessibleChildIds->toArray());
            if (! empty($validIds)) {
                $query->whereHas('children', function ($q) use ($validIds) {
                    $q->whereIn('child_photo.child_id', $validIds);
                });
            }
        }

        if ($tagsFilter = $request->query('tags')) {
            $tagNames = explode(',', $tagsFilter);
            $query->whereHas('tags', function ($q) use ($tagNames) {
                $q->whereIn('name', $tagNames);
            });
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('taken_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->where('taken_at', '<=', $dateTo.' 23:59:59');
        }

        $photos = $query->orderBy('taken_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(48);

        return response()->json([
            'data' => $photos->getCollection()->map(fn (Photo $photo) => array_merge($this->formatPhoto($photo), [
                'thumbnail_url' => $this->storageService->presignedUrl($photo->thumbnail_path, 30),
            ])),
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'per_page' => $photos->perPage(),
                'total' => $photos->total(),
            ],
        ]);
    }

    public function show(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('view', $photo);

        $photo->load(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }]);
        $photo->loadCount('comments');

        $presignedUrl = $this->storageService->presignedUrl($photo->original_path, 10);

        return response()->json([
            'data' => array_merge($this->formatPhoto($photo), [
                'presigned_url' => $presignedUrl,
            ]),
        ]);
    }

    public function thumbnail(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('view', $photo);

        $url = $this->storageService->presignedUrl($photo->thumbnail_path, 10);

        return response()->json([
            'data' => [
                'presigned_url' => $url,
            ],
        ]);
    }

    public function destroy(Request $request, Photo $photo): JsonResponse
    {
        $paths = null;

        DB::transaction(function () use ($request, $photo, &$paths) {
            $photo = Photo::whereKey($photo->id)->lockForUpdate()->firstOrFail();

            Gate::authorize('delete', $photo);

            $photo->children()->detach(
                app(PhotoPolicy::class)->administrableChildIds($request->user(), $photo)->all()
            );

            if ($photo->children()->exists()) {
                return;
            }

            $paths = [$photo->original_path, $photo->thumbnail_path];

            $this->quotaService->removeUsage((int) $photo->stored_bytes);

            $photo->delete();
        });

        if ($paths === null) {
            return response()->json([
                'message' => 'Removed from your children.',
                'remaining' => true,
            ]);
        }

        $this->storageService->deleteMultiple($paths);

        return response()->json(null, 204);
    }

    public function update(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('update', $photo);

        $validated = $request->validate([
            'taken_at' => ['nullable', 'date'],
        ]);

        $photo->update($validated);

        $photo->load(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }]);
        $photo->loadCount('comments');

        return response()->json([
            'data' => $this->formatPhoto($photo),
        ]);
    }

    public function recents(Request $request): JsonResponse
    {
        $user = $request->user();
        $photos = Photo::visibleTo($user)->with(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }])
            ->withCount('comments')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return response()->json([
            'data' => $photos->getCollection()->map(fn (Photo $photo) => array_merge($this->formatPhoto($photo), [
                'thumbnail_url' => $this->storageService->presignedUrl($photo->thumbnail_path, 30),
            ])),
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'per_page' => $photos->perPage(),
                'total' => $photos->total(),
            ],
        ]);
    }

    public function unseenCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Photo::visibleTo($user)->where('uploaded_by', '!=', $user->id);

        if ($user->last_seen_at) {
            $query->where('created_at', '>', $user->last_seen_at);
        }

        return response()->json([
            'data' => [
                'unseen_count' => $query->count(),
            ],
        ]);
    }

    public function markSeen(Request $request): JsonResponse
    {
        $request->user()->update(['last_seen_at' => now()]);

        return response()->json([
            'data' => [
                'last_seen_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Best-effort removal of blobs uploaded for a request that failed.
     *
     * @param  array<int, string>  $paths
     */
    private function cleanupBlobs(array $paths): void
    {
        try {
            $this->storageService->deleteMultiple($paths);
        } catch (\Throwable $e) {
            Log::warning('Failed to clean up blobs after failed upload', ['paths' => $paths, 'error' => $e->getMessage()]);
        }
    }
}
