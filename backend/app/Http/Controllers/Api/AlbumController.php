<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Child;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumController extends Controller
{
    /**
     * List albums for a child (paginated, with photo count + cover thumbnail).
     */
    public function index(Request $request, Child $child): JsonResponse
    {
        $albums = $child->albums()
            ->withCount('photos')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $albums->through(function (Album $album) {
            $cover = $album->photos()->latest('taken_at')->first();

            return [
                'id' => $album->id,
                'title' => $album->title,
                'description' => $album->description,
                'photo_count' => $album->photos_count,
                'cover_thumbnail_path' => $cover?->thumbnail_sm_path,
                'cover_iv' => $cover?->iv_sm,
                'created_by' => $album->created_by,
                'created_at' => $album->created_at?->toISOString(),
                'updated_at' => $album->updated_at?->toISOString(),
            ];
        });

        return response()->json($albums);
    }

    /**
     * Create a new album within a child's profile.
     */
    public function store(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
        ]);

        $album = $child->albums()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $album->id,
                'title' => $album->title,
                'description' => $album->description,
                'photo_count' => 0,
                'cover_thumbnail_path' => null,
                'cover_iv' => null,
                'created_by' => $album->created_by,
                'created_at' => $album->created_at?->toISOString(),
                'updated_at' => $album->updated_at?->toISOString(),
            ],
        ], 201);
    }

    /**
     * Show album details with photo count + cover thumbnail.
     */
    public function show(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        $isMember = $user->children()->where('child_id', $album->child_id)->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Unauthorized access to this album.'], 403);
        }

        $album->loadCount('photos');
        $cover = $album->photos()->latest('taken_at')->first();

        return response()->json([
            'data' => [
                'id' => $album->id,
                'child_id' => $album->child_id,
                'title' => $album->title,
                'description' => $album->description,
                'photo_count' => $album->photos_count,
                'cover_thumbnail_path' => $cover?->thumbnail_sm_path,
                'cover_iv' => $cover?->iv_sm,
                'created_by' => $album->created_by,
                'created_at' => $album->created_at?->toISOString(),
                'updated_at' => $album->updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Update album title/description (admin/owner only).
     */
    public function update(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        $pivot = $user->children()->where('child_id', $album->child_id)->first()?->pivot;

        if (! $pivot || ! in_array($pivot->role, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'You do not have admin access to this album.'], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
        ]);

        $album->update($validated);

        $album->loadCount('photos');
        $cover = $album->photos()->latest('taken_at')->first();

        return response()->json([
            'data' => [
                'id' => $album->id,
                'child_id' => $album->child_id,
                'title' => $album->title,
                'description' => $album->description,
                'photo_count' => $album->photos_count,
                'cover_thumbnail_path' => $cover?->thumbnail_sm_path,
                'cover_iv' => $cover?->iv_sm,
                'created_by' => $album->created_by,
                'created_at' => $album->created_at?->toISOString(),
                'updated_at' => $album->updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Delete album + all photos + object storage blobs + decrement storage (admin/owner only).
     */
    public function destroy(Request $request, Album $album, StorageService $storage): JsonResponse
    {
        $user = $request->user();
        $pivot = $user->children()->where('child_id', $album->child_id)->first()?->pivot;

        if (! $pivot || ! in_array($pivot->role, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'You do not have admin access to this album.'], 403);
        }

        $photos = $album->photos()->get();
        $localPhotos = $photos;

        $totalBytes = 0;
        $pathsToDelete = [];

        foreach ($photos as $photo) {
            $totalBytes += $photo->size_bytes;

            $pathsToDelete[] = $photo->original_path;

            if ($photo->thumbnail_sm_path) {
                $pathsToDelete[] = $photo->thumbnail_sm_path;
            }
            if ($photo->thumbnail_md_path) {
                $pathsToDelete[] = $photo->thumbnail_md_path;
            }
            if ($photo->thumbnail_lg_path) {
                $pathsToDelete[] = $photo->thumbnail_lg_path;
            }
        }

        if (! empty($pathsToDelete)) {
            $storage->deleteMultiple($pathsToDelete);
        }

        $album->delete();

        if ($totalBytes > 0) {
            $storageUsage = $album->child->storageUsage;
            if ($storageUsage) {
                $storageUsage->used_bytes = max(0, $storageUsage->used_bytes - $totalBytes);
                $storageUsage->save();
            }
        }

        return response()->json([
            'message' => 'Album and all its photos have been deleted.',
            'data' => [
                'photos_deleted' => $localPhotos->count(),
                'bytes_reclaimed' => $totalBytes,
            ],
        ]);
    }
}
