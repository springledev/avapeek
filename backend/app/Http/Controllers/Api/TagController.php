<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Tag;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function globalIndex(Request $request): JsonResponse
    {
        $accessibleChildIds = $request->user()->children()->pluck('children.id');

        $tags = Tag::visibleTo($request->user())->withCount(['photos' => function ($q) use ($accessibleChildIds) {
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
                function (string $attribute, mixed $value, Closure $fail) use ($request) {
                    if (Tag::visibleTo($request->user())->where('name', $value)->exists()) {
                        $fail('The name has already been taken.');
                    }
                },
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

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $user = $request->user();
        abort_unless(Tag::visibleTo($user)->whereKey($tag->id)->exists(), 404);
        abort_unless($tag->isDeletableBy($user), 403);

        $tag->delete();

        return response()->json(null, 204);
    }

    public function attach(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('view', $photo);

        $validated = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['required', 'integer', 'exists:tags,id'],
        ]);

        $tagIds = array_values(array_unique($validated['tag_ids']));
        $visibleCount = Tag::visibleTo($request->user())->whereIn('tags.id', $tagIds)->count();
        if ($visibleCount !== count($tagIds)) {
            abort(422, 'One or more tags are invalid.');
        }

        $photo->tags()->syncWithoutDetaching($tagIds);

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
        Gate::authorize('view', $photo);

        $photo->tags()->detach($tag->id);

        return response()->json(null, 204);
    }
}
