<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Child;
use App\Models\Photo;
use App\Models\Tag;

trait FormatsPhotos
{
    /**
     * @param  array<int, int>  $viewerChildIds  the requesting user's child ids; only their tags are
     *                                           returned, as other children's tag names are undecryptable
     *                                           for the viewer. Passed per request: no controller state.
     * @return array<string, mixed>
     */
    protected function formatPhoto(Photo $photo, array $viewerChildIds): array
    {
        return [
            'id' => $photo->id,
            'uuid' => $photo->uuid,
            'uploaded_by' => $photo->uploaded_by,
            'uploader_name' => $photo->uploader?->display_name,
            'original_path' => $photo->original_path,
            'thumbnail_path' => $photo->thumbnail_path,
            'lqip' => $photo->lqip_encrypted,
            'iv_lqip' => $photo->iv_lqip,
            'iv_original' => $photo->iv_original,
            'iv_thumbnail' => $photo->iv_thumbnail,
            'original_mime' => $photo->original_mime,
            'size_bytes' => $photo->size_bytes,
            'width' => $photo->width,
            'height' => $photo->height,
            'exif_encrypted' => $photo->exif_encrypted,
            'iv_exif' => $photo->iv_exif,
            'name_encrypted' => $photo->name_encrypted,
            'iv_name' => $photo->iv_name,
            'taken_at' => $photo->taken_at?->toISOString(),
            'created_at' => $photo->created_at?->toISOString(),
            'comment_count' => $photo->comments_count ?? $photo->comments()->count(),
            'tags' => $photo->tags
                ?->whereIn('child_id', $viewerChildIds)
                ->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'child_id' => $tag->child_id,
                    'name_encrypted' => $tag->name_encrypted,
                    'iv_name' => $tag->iv_name,
                ])
                ->values(),
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
