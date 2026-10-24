<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\Tag;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keyset-paginated, ciphertext-only listings used by the client-side "export everything" zip.
 * Every query is re-scoped to the requested child through the child_photo pivot.
 */
class ChildExportController extends Controller
{
    private const PRESIGN_MINUTES = 15;

    public function __construct(protected StorageService $storageService) {}

    public function photos(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $afterId = (int) ($validated['after_id'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 100);

        $photos = Photo::query()
            ->whereHas('children', fn ($q) => $q->where('children.id', $child->id))
            ->where('photos.id', '>', $afterId)
            ->with([
                'uploader:id,display_name',
                'tags' => fn ($q) => $q->where('tags.child_id', $child->id),
                'children' => fn ($q) => $q->where('children.id', $child->id)->select('children.id'),
            ])
            ->orderBy('photos.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $photos->count() > $limit;
        $page = $photos->take($limit);

        $meta = ['next_after_id' => $hasMore ? $page->last()->id : null];
        if ($afterId === 0) {
            $meta['total'] = Photo::query()
                ->whereHas('children', fn ($q) => $q->where('children.id', $child->id))
                ->count();
        }

        return response()->json([
            'data' => $page->map(fn (Photo $photo) => $this->formatPhoto($photo))->values(),
            'meta' => $meta,
        ]);
    }

    public function comments(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $afterId = (int) ($validated['after_id'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 200);

        $comments = Comment::query()
            ->whereHas('photo.children', fn ($q) => $q->where('children.id', $child->id))
            ->where('comments.id', '>', $afterId)
            ->with('user:id,display_name')
            ->orderBy('comments.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $comments->count() > $limit;
        $page = $comments->take($limit);

        return response()->json([
            'data' => $page->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'photo_id' => $comment->photo_id,
                'user_id' => $comment->user_id,
                'author_name' => $comment->user?->display_name,
                'body_encrypted' => $comment->body_encrypted,
                'iv_body' => $comment->iv_body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['next_after_id' => $hasMore ? $page->last()->id : null],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPhoto(Photo $photo): array
    {
        $pivot = $photo->children->first()?->pivot;

        return [
            'id' => $photo->id,
            'uuid' => $photo->uuid,
            'original_mime' => $photo->original_mime,
            'size_bytes' => $photo->size_bytes,
            'width' => $photo->width,
            'height' => $photo->height,
            'iv_original' => $photo->iv_original,
            'name_encrypted' => $photo->name_encrypted,
            'iv_name' => $photo->iv_name,
            'exif_encrypted' => $photo->exif_encrypted,
            'iv_exif' => $photo->iv_exif,
            'taken_at' => $photo->taken_at?->toISOString(),
            'created_at' => $photo->created_at?->toISOString(),
            'uploader_name' => $photo->uploader?->display_name,
            'tags' => $photo->tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'child_id' => $tag->child_id,
                'name_encrypted' => $tag->name_encrypted,
                'iv_name' => $tag->iv_name,
            ])->values(),
            'encrypted_pek' => $pivot?->encrypted_pek,
            'iv_pek' => $pivot?->iv_pek,
            'presigned_url' => $this->storageService->presignedUrl($photo->original_path, self::PRESIGN_MINUTES),
        ];
    }
}
