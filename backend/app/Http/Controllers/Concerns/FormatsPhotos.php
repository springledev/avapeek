<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Child;
use App\Models\Photo;
use App\Models\Tag;

trait FormatsPhotos
{
    /**
     * @return array<string, mixed>
     */
    protected function formatPhoto(Photo $photo): array
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
            'taken_at' => $photo->taken_at?->toISOString(),
            'created_at' => $photo->created_at?->toISOString(),
            'comment_count' => $photo->comments_count ?? $photo->comments()->count(),
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
