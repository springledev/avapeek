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

        $this->storageService->upload($originalPath, file_get_contents($fileObj->getPathname()));
        $this->storageService->upload($thumbPath, file_get_contents($thumbObj->getPathname()));

        $photo = Photo::create([
            'uuid' => $uuid,
            'uploaded_by' => $user->id,
            'original_path' => $originalPath,
            'thumbnail_path' => $thumbPath,
            'iv_original' => $validated['iv'],
            'iv_thumbnail' => $validated['iv_thumbnail'],
            'original_mime' => $validated['original_mime'],
            'size_bytes' => $validated['size_bytes'],
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
                $tag = Tag::firstOrCreate(
                    ['name' => $tagName],
                    ['created_by' => $user->id],
                );
                $tagIds[] = $tag->id;
            }
            $photo->tags()->sync($tagIds);
        }

        $this->quotaService->addUsage($totalBytes);

        $photo->load(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }]);

        return response()->json([
            'data' => $this->formatPhoto($photo),
        ], 201);
    }

    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id');

        $query = Photo::whereHas('children', function ($q) use ($accessibleChildIds) {
            $q->whereIn('child_photo.child_id', $accessibleChildIds);
        })->with(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }]);

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
        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id');

        $photoChildIds = $photo->children()->pluck('children.id');
        if ($photoChildIds->intersect($accessibleChildIds)->isEmpty()) {
            abort(403, 'You do not have access to this photo.');
        }

        $photo->load(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }]);

        $presignedUrl = $this->storageService->presignedUrl($photo->original_path, 10);

        return response()->json([
            'data' => array_merge($this->formatPhoto($photo), [
                'presigned_url' => $presignedUrl,
            ]),
        ]);
    }

    public function thumbnail(Photo $photo): JsonResponse
    {
        $url = $this->storageService->presignedUrl($photo->thumbnail_path, 10);

        return response()->json([
            'data' => [
                'presigned_url' => $url,
            ],
        ]);
    }

    public function destroy(Request $request, Photo $photo): JsonResponse
    {
        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id');

        $photoChildIds = $photo->children()->pluck('children.id');
        if ($photoChildIds->intersect($accessibleChildIds)->isEmpty()) {
            abort(403, 'You do not have access to this photo.');
        }

        $role = null;
        $childWithAccess = $user->children()
            ->whereIn('children.id', $photoChildIds)
            ->first();
        if ($childWithAccess) {
            $role = $childWithAccess->pivot->role;
        }

        if (! in_array($role, ['owner', 'admin'], true) && $photo->uploaded_by !== $user->id) {
            return response()->json(['message' => 'You do not have permission to delete this photo.'], 403);
        }

        $paths = [$photo->original_path, $photo->thumbnail_path];

        $totalBytes = 0;
        foreach ($paths as $path) {
            try {
                $size = Storage::disk('s3')->size($path);
                if ($size !== false) {
                    $totalBytes += $size;
                }
            } catch (\Throwable) {
                // File may not exist on S3; skip size calculation
            }
        }

        $this->storageService->deleteMultiple($paths);

        $this->quotaService->removeUsage($totalBytes);

        $photo->delete();

        return response()->json(null, 204);
    }

    public function recents(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id');

        $photos = Photo::whereHas('children', function ($q) use ($accessibleChildIds) {
            $q->whereIn('child_photo.child_id', $accessibleChildIds);
        })->with(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }])
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
        $accessibleChildIds = $user->children()->pluck('children.id');

        $query = Photo::whereHas('children', function ($q) use ($accessibleChildIds) {
            $q->whereIn('child_photo.child_id', $accessibleChildIds);
        });

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

    private function formatPhoto(Photo $photo): array
    {
        return [
            'id' => $photo->id,
            'uuid' => $photo->uuid,
            'uploaded_by' => $photo->uploaded_by,
            'uploader_name' => $photo->uploader?->display_name,
            'original_path' => $photo->original_path,
            'thumbnail_path' => $photo->thumbnail_path,
            'iv_original' => $photo->iv_original,
            'iv_thumbnail' => $photo->iv_thumbnail,
            'original_mime' => $photo->original_mime,
            'size_bytes' => $photo->size_bytes,
            'width' => $photo->width,
            'height' => $photo->height,
            'exif_encrypted' => $photo->exif_encrypted,
            'iv_exif' => $photo->iv_exif,
            'taken_at' => $photo->taken_at?->toISOString(),
            'created_at' => $photo->created_at?->toISOString(),
            'tags' => $photo->tags?->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ]),
            'children' => $photo->children?->map(fn (Child $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'avatar_color' => $child->avatar_color,
                'encrypted_pek' => $child->pivot->encrypted_pek,
                'iv_pek' => $child->pivot->iv_pek,
            ]),
        ];
    }
}
