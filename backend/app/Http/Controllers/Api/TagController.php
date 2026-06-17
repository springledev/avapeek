<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function globalIndex(Request $request): JsonResponse
    {
        $accessibleChildIds = $request->user()->children()->pluck('children.id');

        $tags = Tag::withCount(['photos' => function ($q) use ($accessibleChildIds) {
            $q->whereHas('children', function ($q2) use ($accessibleChildIds) {
                $q2->whereIn('child_photo.child_id', $accessibleChildIds);
            });
        }])
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'photo_count' => $tag->photos_count,
                'created_at' => $tag->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[\pL\pN\s\-#&.,]+$/u',
                'unique:tags,name',
            ],
        ]);

        $tag = Tag::create([
            'name' => $validated['name'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'photo_count' => 0,
                'created_at' => $tag->created_at?->toISOString(),
            ],
        ], 201);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json(null, 204);
    }

    public function attach(Request $request, Photo $photo): JsonResponse
    {
        $userChildIds = $request->user()->children()->pluck('children.id');
        $photoChildIds = $photo->children()->pluck('children.id');
        if ($photoChildIds->intersect($userChildIds)->isEmpty()) {
            abort(403);
        }

        $validated = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ]);

        $photo->tags()->syncWithoutDetaching($validated['tag_ids']);

        $photo->load('tags');

        return response()->json([
            'data' => [
                'photo_id' => $photo->id,
                'tags' => $photo->tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ]),
            ],
        ]);
    }

    public function detach(Request $request, Photo $photo, Tag $tag): JsonResponse
    {
        $userChildIds = $request->user()->children()->pluck('children.id');
        $photoChildIds = $photo->children()->pluck('children.id');
        if ($photoChildIds->intersect($userChildIds)->isEmpty()) {
            abort(403);
        }

        $photo->tags()->detach($tag->id);

        return response()->json(null, 204);
    }
}
