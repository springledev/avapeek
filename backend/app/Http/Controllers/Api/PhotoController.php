<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsPhotos;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteUploadRequest;
use App\Http\Requests\PhotoMetadataRequest;
use App\Http\Requests\UploadIntentRequest;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\UploadIntent;
use App\Models\User;
use App\Services\PhotoRemovalService;
use App\Services\QuotaService;
use App\Services\StorageService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PhotoController extends Controller
{
    use FormatsPhotos;

    public function __construct(
        protected StorageService $storageService,
        protected QuotaService $quotaService,
    ) {}

    private const INTENT_MINUTES = 15;

    public function upload(PhotoMetadataRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $childrenData = json_decode($validated['children'], true);

        $user = $request->user();

        if ($invalid = $this->invalidChildIdsResponse($user, $childrenData)) {
            return $invalid;
        }

        if ($invalid = $this->invalidTagIdsResponse($validated, $childrenData)) {
            return $invalid;
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
        [$originalPath, $thumbPath] = $this->blobPaths($uuid, $validated['original_mime']);

        try {
            $this->storageService->upload($originalPath, file_get_contents($fileObj->getPathname()));
            $this->storageService->upload($thumbPath, file_get_contents($thumbObj->getPathname()));

            $photo = $this->persistPhoto($validated, $childrenData, $user, $uuid, $originalPath, $thumbPath, $totalBytes);
        } catch (\Throwable $e) {
            $this->cleanupBlobs([$originalPath, $thumbPath]);

            throw $e;
        }

        return $this->createdPhotoResponse($photo, $user);
    }

    /**
     * Reserve an upload slot and hand out presigned PUT URLs for a direct upload.
     */
    public function uploadIntent(UploadIntentRequest $request): JsonResponse
    {
        if (! config('avapeek.uploads.direct')) {
            abort(404);
        }

        $validated = $request->validated();
        $user = $request->user();

        $pending = UploadIntent::where('user_id', $user->id)->unexpired()->count();
        if ($pending >= (int) config('avapeek.uploads.max_pending_intents')) {
            return response()->json(['message' => 'Too many uploads in progress. Finish or wait for them to expire before starting more.'], 429);
        }

        $uuid = (string) str()->uuid();
        [$originalPath, $thumbPath] = $this->blobPaths($uuid, $validated['original_mime']);
        $total = $validated['original_bytes'] + $validated['thumbnail_bytes'];

        $intent = $this->quotaService->reserve($total, fn (): UploadIntent => UploadIntent::create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'original_path' => $originalPath,
            'thumbnail_path' => $thumbPath,
            'original_mime' => $validated['original_mime'],
            'expected_original_bytes' => $validated['original_bytes'],
            'expected_thumbnail_bytes' => $validated['thumbnail_bytes'],
            'reserved_bytes' => $total,
            'expires_at' => now()->addMinutes(self::INTENT_MINUTES),
        ]));

        if ($intent === null) {
            return response()->json(['message' => 'Storage quota exceeded.'], 413);
        }

        try {
            $original = $this->storageService->presignedPutUrl($originalPath, self::INTENT_MINUTES);
            $thumbnail = $this->storageService->presignedPutUrl($thumbPath, self::INTENT_MINUTES);
        } catch (\Throwable $e) {
            $intent->delete();

            throw $e;
        }

        return response()->json([
            'data' => [
                'uuid' => $intent->uuid,
                'original' => $original,
                'thumbnail' => $thumbnail,
                'expires_at' => $intent->expires_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Verify the directly uploaded objects and create the photo row.
     */
    public function complete(CompleteUploadRequest $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        try {
            return DB::transaction(fn (): JsonResponse => $this->completeClaimed($request, $user, $uuid));
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($conflict = $this->cleanupFailedComplete($uuid, $user)) {
                return $conflict;
            }

            throw $e;
        }
    }

    /**
     * Failure cleanup after complete() rolled back, under its own lock on the intent so
     * a parallel complete cannot commit a photo between our checks and the blob deletion.
     * Returns a 409 when the upload was completed meanwhile (its blobs are live and kept).
     */
    private function cleanupFailedComplete(string $uuid, User $user): ?JsonResponse
    {
        return DB::transaction(function () use ($uuid, $user): ?JsonResponse {
            $intent = UploadIntent::where('uuid', $uuid)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (Photo::where('uuid', $uuid)->exists()) {
                Log::warning('Concurrent complete for an already completed upload', ['uuid' => $uuid]);
                $intent?->delete();

                return response()->json(['message' => 'This upload was already completed.'], 409);
            }

            if ($intent !== null) {
                $this->discardIntent($intent);
            }

            return null;
        });
    }

    /**
     * Runs inside a transaction holding a row lock on the intent, so parallel
     * completes of the same intent are serialized and only one can win.
     */
    private function completeClaimed(CompleteUploadRequest $request, User $user, string $uuid): JsonResponse
    {
        $intent = UploadIntent::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->unexpired()
            ->lockForUpdate()
            ->first();

        if ($intent === null) {
            // Retry after a lost 201: the photo exists and is this user's, so answer idempotently.
            $existing = Photo::where('uuid', $uuid)->where('uploaded_by', $user->id)->first();
            if ($existing !== null) {
                return $this->createdPhotoResponse($existing, $user, 200);
            }

            abort(404);
        }

        $validated = $request->validated();

        if ($validated['original_mime'] !== $intent->original_mime) {
            return response()->json(['message' => 'original_mime does not match the upload intent.'], 422);
        }

        $childrenData = json_decode($validated['children'], true);

        if ($invalid = $this->invalidChildIdsResponse($user, $childrenData)) {
            $this->discardIntent($intent);

            return $invalid;
        }

        if ($invalid = $this->invalidTagIdsResponse($validated, $childrenData)) {
            $this->discardIntent($intent);

            return $invalid;
        }

        try {
            $originalBytes = $this->storageService->objectSize($intent->original_path);
            $thumbBytes = $this->storageService->objectSize($intent->thumbnail_path);
        } catch (\Throwable $e) {
            // Transient storage error: keep blobs and intent so the client can retry.
            Log::warning('Storage HEAD failed during complete', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Storage is temporarily unavailable. Please retry.'], 503);
        }

        if ($originalBytes !== $intent->expected_original_bytes || $thumbBytes !== $intent->expected_thumbnail_bytes) {
            $this->discardIntent($intent);

            return response()->json(['message' => 'Uploaded objects are missing or have an unexpected size.'], 422);
        }

        $photo = $this->persistPhoto(
            $validated, $childrenData, $user, $intent->uuid, $intent->original_path, $intent->thumbnail_path,
            $originalBytes + $thumbBytes,
            fn () => $intent->delete(),
        );

        return $this->createdPhotoResponse($photo, $user);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function blobPaths(string $uuid, string $mime): array
    {
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default => 'bin',
        };

        return ["photos/{$uuid}.{$ext}.enc", "photos/{$uuid}_thumb.webp.enc"];
    }

    /**
     * @param  array<int|string, mixed>  $childrenData
     */
    private function invalidChildIdsResponse(User $user, array $childrenData): ?JsonResponse
    {
        $accessibleChildIds = $user->children()->pluck('children.id')->toArray();
        $unknownIds = array_diff(array_keys($childrenData), $accessibleChildIds);

        if (! empty($unknownIds)) {
            return response()->json(['message' => 'Invalid child IDs: '.implode(', ', $unknownIds)], 422);
        }

        return null;
    }

    /**
     * Every tag id must exist and belong to a child this photo is being linked to
     * (the user is already known to be a member of all of them). Unknown and
     * foreign ids give the same response.
     *
     * @param  array<int|string, mixed>  $childrenData
     * @param  array<string, mixed>  $validated
     */
    private function invalidTagIdsResponse(array $validated, array $childrenData): ?JsonResponse
    {
        $tagIds = array_values(array_unique($validated['tag_ids'] ?? []));
        if ($tagIds === []) {
            return null;
        }

        $matching = Tag::whereIn('id', $tagIds)
            ->whereIn('child_id', array_map('intval', array_keys($childrenData)))
            ->count();

        if ($matching !== count($tagIds)) {
            return response()->json(['message' => 'One or more tags are invalid.'], 422);
        }

        return null;
    }

    private function discardIntent(UploadIntent $intent): void
    {
        // Never delete blobs a live photo points at.
        if (Photo::where('uuid', $intent->uuid)->exists()) {
            $intent->delete();

            return;
        }

        $this->cleanupBlobs([$intent->original_path, $intent->thumbnail_path]);
        $intent->delete();
    }

    /**
     * Create the photo, its child wraps, tags and usage in one transaction.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int|string, array{encrypted_pek: string, iv_pek: string}>  $childrenData
     */
    private function persistPhoto(
        array $validated,
        array $childrenData,
        User $user,
        string $uuid,
        string $originalPath,
        string $thumbPath,
        int $totalBytes,
        ?Closure $inTransaction = null,
    ): Photo {
        return DB::transaction(function () use ($validated, $childrenData, $user, $uuid, $originalPath, $thumbPath, $totalBytes, $inTransaction): Photo {
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
                'name_encrypted' => $validated['name_encrypted'] ?? null,
                'iv_name' => $validated['iv_name'] ?? null,
                'taken_at' => $validated['taken_at'] ?? null,
            ]);

            foreach ($childrenData as $childId => $wrap) {
                $photo->children()->attach($childId, [
                    'encrypted_pek' => $wrap['encrypted_pek'],
                    'iv_pek' => $wrap['iv_pek'],
                ]);
            }

            if (! empty($validated['tag_ids'])) {
                $tagIds = array_values(array_unique($validated['tag_ids']));

                // Re-check under a share lock so a tag deleted since validation gives a 422, not an FK 500.
                $lockedIds = Tag::lockedLinkableIdsQuery($tagIds, array_map('intval', array_keys($childrenData)))->pluck('id');
                if ($lockedIds->unique()->count() !== count($tagIds)) {
                    throw ValidationException::withMessages(['tag_ids' => 'One or more tags are invalid.']);
                }

                $photo->tags()->sync($tagIds);
            }

            $this->quotaService->addUsage($totalBytes);

            if ($inTransaction !== null) {
                $inTransaction();
            }

            return $photo;
        });
    }

    private function createdPhotoResponse(Photo $photo, User $user, int $status = 201): JsonResponse
    {
        $photo->load(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }]);
        $photo->loadCount('comments');

        return response()->json([
            'data' => $this->formatPhoto($photo, $user->children()->pluck('children.id')->all()),
        ], $status);
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

        // An array value (?tag_ids[]=1) is ignored rather than coerced.
        if (is_string($tagIdsFilter = $request->query('tag_ids')) && $tagIdsFilter !== '') {
            $tagIds = array_values(array_filter(array_map('intval', explode(',', $tagIdsFilter))));
            $query->whereHas('tags', function ($q) use ($tagIds, $accessibleChildIds) {
                $q->whereIn('tags.id', $tagIds)->whereIn('tags.child_id', $accessibleChildIds);
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
            'data' => $photos->getCollection()->map(fn (Photo $photo) => array_merge($this->formatPhoto($photo, $accessibleChildIds->all()), [
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
            'data' => array_merge($this->formatPhoto($photo, $request->user()->children()->pluck('children.id')->all()), [
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

    public function destroy(Request $request, Photo $photo, PhotoRemovalService $removal): JsonResponse
    {
        $result = $removal->remove($request->user(), $photo->id);

        if ($result['outcome'] === PhotoRemovalService::INVISIBLE && $result['denial'] === null) {
            abort(404);
        }

        $result['denial']?->authorize();

        if ($result['outcome'] === PhotoRemovalService::DETACHED) {
            return response()->json([
                'message' => 'Removed from your children.',
                'remaining' => true,
            ]);
        }

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
            'data' => $this->formatPhoto($photo, $request->user()->children()->pluck('children.id')->all()),
        ]);
    }

    public function recents(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessibleChildIds = $user->children()->pluck('children.id');
        $photos = Photo::visibleTo($user)->with(['tags', 'children' => function ($q) {
            $q->select('children.id', 'children.name', 'children.avatar_color');
        }, 'uploader' => function ($q) {
            $q->select('id', 'display_name');
        }])
            ->withCount('comments')
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return response()->json([
            'data' => $photos->getCollection()->map(fn (Photo $photo) => array_merge($this->formatPhoto($photo, $accessibleChildIds->all()), [
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
