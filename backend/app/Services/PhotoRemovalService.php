<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\User;
use App\Policies\PhotoPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes a photo from the children the user administers. The photo row and its blobs
 * are only deleted when no child links it any more. Stateless: safe under Octane.
 */
class PhotoRemovalService
{
    public const DELETED = 'deleted';

    public const DETACHED = 'detached';

    /** The photo is visible to the user but they may not remove it. */
    public const FORBIDDEN = 'forbidden';

    /** The photo does not exist or the user may not even view it. */
    public const INVISIBLE = 'invisible';

    public function __construct(
        protected StorageService $storageService,
        protected QuotaService $quotaService,
        protected PhotoPolicy $policy,
    ) {}

    /**
     * @return array{outcome: string, denial: ?Response}
     */
    public function remove(User $user, int $photoId): array
    {
        $paths = null;
        $denial = null;
        $outcome = self::INVISIBLE;

        DB::transaction(function () use ($user, $photoId, &$paths, &$denial, &$outcome): void {
            $photo = Photo::whereKey($photoId)->lockForUpdate()->first();

            if ($photo === null) {
                return;
            }

            $view = $this->policy->view($user, $photo);
            if ($view->denied()) {
                $denial = $view;

                return;
            }

            $delete = $this->policy->delete($user, $photo);
            if ($delete->denied()) {
                $denial = $delete;
                $outcome = self::FORBIDDEN;

                return;
            }

            $photo->children()->detach($this->policy->administrableChildIds($user, $photo)->all());

            if ($photo->children()->exists()) {
                $outcome = self::DETACHED;

                return;
            }

            $paths = [$photo->original_path, $photo->thumbnail_path];

            $this->quotaService->removeUsage((int) $photo->stored_bytes);

            $photo->delete();
            $outcome = self::DELETED;
        });

        if ($paths !== null) {
            $this->deleteBlobs($paths);
        }

        return ['outcome' => $outcome, 'denial' => $denial];
    }

    /**
     * Blobs of a committed deletion; a storage failure must not undo or hide the removal
     * (orphaned blobs are reclaimed by the prune job).
     *
     * @param  array<int, string>  $paths
     */
    private function deleteBlobs(array $paths): void
    {
        try {
            $this->storageService->deleteMultiple($paths);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete blobs of a removed photo', ['paths' => $paths, 'error' => $e->getMessage()]);
        }
    }
}
