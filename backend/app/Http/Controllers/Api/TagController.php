<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Models\Child;
use App\Models\Photo;
use App\Models\Tag;
use App\Policies\PhotoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tag names are client-side encrypted with the child's DEK; this controller only
 * stores, lists and links ciphertext and never sees or filters on a name.
 */
class TagController extends Controller
{
    public function index(Request $request, Child $child): JsonResponse
    {
        $tags = Tag::where('child_id', $child->id)
            ->withCount(['photos' => function ($q) use ($child) {
                $q->whereHas('children', fn ($q2) => $q2->where('child_photo.child_id', $child->id));
            }])
            ->orderBy('id')
            ->get()
            ->map(fn (Tag $tag) => $this->formatTag($tag, $tag->photos_count));

        return response()->json(['data' => $tags]);
    }

    public function store(StoreTagRequest $request, Child $child): JsonResponse
    {
        $validated = $request->validated();

        // The child row lock serializes concurrent creates, so the cap is exact.
        $tag = DB::transaction(function () use ($request, $child, $validated): ?Tag {
            Child::whereKey($child->id)->lockForUpdate()->first();

            if (Tag::where('child_id', $child->id)->count() >= Tag::MAX_PER_CHILD) {
                return null;
            }

            return Tag::create([
                'child_id' => $child->id,
                'created_by' => $request->user()->id,
                'name_encrypted' => $validated['name_encrypted'],
                'iv_name' => $validated['iv_name'],
            ]);
        });

        if ($tag === null) {
            return response()->json([
                'message' => 'This child has reached the maximum of '.Tag::MAX_PER_CHILD.' tags.',
            ], 422);
        }

        return response()->json(['data' => $this->formatTag($tag, 0)], 201);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $user = $request->user();

        // Unknown and foreign tags are indistinguishable.
        abort_if(Gate::forUser($user)->denies('view', $tag), 404);
        Gate::authorize('delete', $tag);

        $tag->delete();

        return response()->json(null, 204);
    }

    public function attach(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('view', $photo);

        $validated = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['required', 'integer'],
        ]);

        $tagIds = array_values(array_unique($validated['tag_ids']));
        $tags = Tag::whereIn('id', $tagIds)->get();
        $policy = app(PhotoPolicy::class);
        $user = $request->user();

        if ($tags->count() !== count($tagIds)
            || $tags->contains(fn (Tag $tag) => $policy->attachTag($user, $photo, $tag)->denied())) {
            abort(422, 'One or more tags are invalid.');
        }

        $photo->tags()->syncWithoutDetaching($tagIds);

        return response()->json([
            'data' => [
                'photo_id' => $photo->id,
                'tags' => $photo->tags()->forUserChildren($user)->get()
                    ->map(fn (Tag $tag) => $this->formatPhotoTag($tag))
                    ->values(),
            ],
        ]);
    }

    public function detach(Request $request, Photo $photo, Tag $tag): JsonResponse
    {
        Gate::authorize('view', $photo);

        // A tag of a child the user cannot access is left untouched (no-op).
        if (Gate::forUser($request->user())->allows('view', $tag)) {
            $photo->tags()->detach($tag->id);
        }

        return response()->json(null, 204);
    }

    /**
     * @return array{id: int, name_encrypted: string, iv_name: string, photo_count: int, created_at: ?string}
     */
    private function formatTag(Tag $tag, int $photoCount): array
    {
        return [
            'id' => $tag->id,
            'name_encrypted' => $tag->name_encrypted,
            'iv_name' => $tag->iv_name,
            'photo_count' => $photoCount,
            'created_at' => $tag->created_at?->toISOString(),
        ];
    }

    /**
     * @return array{id: int, child_id: int, name_encrypted: string, iv_name: string}
     */
    private function formatPhotoTag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'child_id' => $tag->child_id,
            'name_encrypted' => $tag->name_encrypted,
            'iv_name' => $tag->iv_name,
        ];
    }
}
