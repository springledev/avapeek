<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Photo;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    /**
     * List all tags for a child with photo counts.
     */
    public function index(Child $child): JsonResponse
    {
        $tags = Tag::where('child_id', $child->id)
            ->withCount('photos')
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

    /**
     * Create a new tag for a child.
     */
    public function store(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'unique:tags,name,NULL,id,child_id,'.$child->id,
            ],
        ]);

        $tag = Tag::create([
            'child_id' => $child->id,
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

    /**
     * Delete a tag and all its photo associations.
     * Does NOT delete photos.
     */
    public function destroy(Child $child, Tag $tag): JsonResponse
    {
        if ($tag->child_id !== $child->id) {
            abort(404);
        }

        $tag->delete();

        return response()->json(null, 204);
    }

    /**
     * Attach tags to a photo.
     */
    public function attach(Request $request, Child $child, Photo $photo): JsonResponse
    {
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

    /**
     * Detach a tag from a photo.
     */
    public function detach(Request $request, Child $child, Photo $photo, Tag $tag): JsonResponse
    {
        $photo->tags()->detach($tag->id);

        return response()->json(null, 204);
    }
}
