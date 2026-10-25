<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeletePhotosRequest;
use App\Http\Requests\BulkTagPhotosRequest;
use App\Http\Requests\SharePhotosRequest;
use App\Models\Photo;
use App\Models\Tag;
use App\Policies\PhotoPolicy;
use App\Services\PhotoRemovalService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Bulk photo operations. Photos the user cannot view are omitted from every response,
 * so unknown, invisible and foreign ids are indistinguishable. Holds no per-request state.
 */
class PhotoBulkController extends Controller
{
    public function destroy(BulkDeletePhotosRequest $request, PhotoRemovalService $removal): JsonResponse
    {
        $deleted = [];
        $detached = [];
        $forbidden = [];

        foreach ($request->validated('photo_ids') as $photoId) {
            $result = $removal->remove($request->user(), (int) $photoId);

            match ($result['outcome']) {
                PhotoRemovalService::DELETED => $deleted[] = (int) $photoId,
                PhotoRemovalService::DETACHED => $detached[] = (int) $photoId,
                PhotoRemovalService::FORBIDDEN => $forbidden[] = (int) $photoId,
                default => null,
            };
        }

        return response()->json([
            'deleted' => $deleted,
            'detached' => $detached,
            'forbidden' => $forbidden,
        ]);
    }

    /**
     * A photo is updated only when every requested (photo, tag) pair passes `attachTag`;
     * otherwise nothing is attached to it and it is reported as skipped (no reason given).
     */
    public function tags(BulkTagPhotosRequest $request, PhotoPolicy $policy): JsonResponse
    {
        $user = $request->user();
        $assignments = $request->validated('assignments');

        $tagIds = collect($assignments)->pluck('tag_ids')->flatten()->unique()->values();
        $tags = Tag::whereIn('id', $tagIds)->get()->keyBy('id');
        $photos = Photo::whereIn('id', collect($assignments)->pluck('photo_id'))->get()->keyBy('id');

        $updated = [];
        $skipped = [];

        DB::transaction(function () use ($assignments, $tags, $photos, $policy, $user, &$updated, &$skipped): void {
            foreach ($assignments as $assignment) {
                $photo = $photos->get($assignment['photo_id']);

                if ($photo === null || $policy->view($user, $photo)->denied()) {
                    continue;
                }

                $allAllowed = collect($assignment['tag_ids'])->every(
                    fn (int $tagId) => ($tag = $tags->get($tagId)) !== null
                        && $policy->attachTag($user, $photo, $tag)->allowed()
                );

                if (! $allAllowed) {
                    $skipped[] = $photo->id;

                    continue;
                }

                $photo->tags()->syncWithoutDetaching($assignment['tag_ids']);
                $updated[] = $photo->id;
            }
        });

        return response()->json(['updated' => $updated, 'skipped' => $skipped]);
    }

    public function share(SharePhotosRequest $request, PhotoPolicy $policy): JsonResponse
    {
        $user = $request->user();

        // A child the user is not in is indistinguishable from an unknown one.
        $target = $user->children()->whereKey($request->validated('child_id'))->first();
        abort_if($target === null, 404, 'Child not found.');

        $shared = [];
        $skipped = [];

        foreach ($request->validated('photos') as $entry) {
            $photo = Photo::find($entry['photo_id']);

            if ($photo === null || $policy->share($user, $photo, $target)->denied()) {
                continue;
            }

            try {
                // Never overwrite an existing wrap; the PK makes concurrent shares safe.
                $inserted = DB::transaction(fn () => DB::table('child_photo')->insertOrIgnore([
                    'child_id' => $target->id,
                    'photo_id' => $photo->id,
                    'encrypted_pek' => $entry['encrypted_pek'],
                    'iv_pek' => $entry['iv_pek'],
                    'created_at' => now(),
                ]));
            } catch (QueryException $e) {
                // Photo or child deleted concurrently (foreign key); treat as not visible.
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }

                continue;
            }

            if ($inserted > 0) {
                $shared[] = $photo->id;
            } else {
                $skipped[] = $photo->id;
            }
        }

        return response()->json(['shared' => $shared, 'skipped' => $skipped]);
    }
}
