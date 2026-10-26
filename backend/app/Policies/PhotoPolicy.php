<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Collection;

class PhotoPolicy
{
    private const ROLE_RANK = ['member' => 1, 'admin' => 2, 'owner' => 3];

    /**
     * Roles the user holds in each child the photo is linked to, keyed by child id.
     *
     * @return Collection<int, string>
     */
    public function rolesByChild(User $user, Photo $photo): Collection
    {
        $photoChildIds = $photo->children()->pluck('children.id');

        return $user->children()
            ->whereIn('children.id', $photoChildIds)
            ->get()
            ->mapWithKeys(fn ($child) => [$child->id => (string) $child->pivot->role]);
    }

    /**
     * The highest-privileged role the user holds across the children sharing the photo.
     */
    public function role(User $user, Photo $photo): ?string
    {
        return $this->rolesByChild($user, $photo)
            ->sortByDesc(fn (string $role) => self::ROLE_RANK[$role] ?? 0)
            ->first();
    }

    /**
     * The user's membership rows keyed by child id (one query), for computing abilities
     * of many photos without per-photo queries.
     *
     * @return Collection<int, ChildUser>
     */
    public function membershipsFor(User $user): Collection
    {
        return ChildUser::where('user_id', $user->id)->get()->keyBy('child_id');
    }

    /**
     * Child ids (linked to the photo) the user may remove the photo from: children where they
     * are owner/admin, or, if they uploaded the photo, children where they can still upload
     * (so a member whose upload permission was revoked loses that right).
     *
     * @return Collection<int, int>
     */
    public function administrableChildIds(User $user, Photo $photo): Collection
    {
        $linkedIds = $photo->children()->pluck('children.id')->all();

        return $this->administrableAmong($user->id, $photo->uploaded_by, $linkedIds, $this->membershipsFor($user));
    }

    /**
     * Pure form of {@see administrableChildIds()} working on already loaded data.
     *
     * @param  array<int, int>  $linkedChildIds
     * @param  Collection<int, ChildUser>  $memberships  the user's memberships keyed by child id
     * @return Collection<int, int>
     */
    public function administrableAmong(int $userId, ?int $uploadedBy, array $linkedChildIds, Collection $memberships): Collection
    {
        $isUploader = $uploadedBy === $userId;

        return collect($linkedChildIds)
            ->filter(function (int $childId) use ($memberships, $isUploader): bool {
                $membership = $memberships->get($childId);
                if ($membership === null) {
                    return false;
                }

                if (in_array($membership->role, ['owner', 'admin'], true)) {
                    return true;
                }

                return $isUploader && $membership->can_upload;
            })
            ->values();
    }

    /**
     * Whether the user may delete / edit a photo, from loaded data (no queries).
     * Mirrors {@see delete()} and {@see update()}; `$photo->children` must be loaded.
     *
     * @param  Collection<int, ChildUser>  $memberships
     * @return array{can_delete: bool, can_edit: bool}
     */
    public function abilities(int $userId, Photo $photo, Collection $memberships): array
    {
        $linkedIds = $photo->children->pluck('id')->map(fn ($id) => (int) $id)->all();
        $administrable = $this->administrableAmong($userId, $photo->uploaded_by, $linkedIds, $memberships);

        return [
            'can_delete' => $administrable->isNotEmpty(),
            'can_edit' => $administrable->isNotEmpty() && count($linkedIds) === $administrable->count(),
        ];
    }

    /**
     * Any member of any child the photo is linked to may view it (and its comments and tags).
     */
    public function view(User $user, Photo $photo): Response
    {
        return $this->rolesByChild($user, $photo)->isNotEmpty()
            ? Response::allow()
            : Response::deny('You do not have access to this photo.');
    }

    /**
     * Editing changes the photo row shared by every copy, so the user must be able to
     * administer every child the photo is linked to.
     */
    public function update(User $user, Photo $photo): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        $linkedIds = $photo->children()->pluck('children.id');
        $administrable = $this->administrableChildIds($user, $photo);

        return $administrable->isNotEmpty() && $linkedIds->diff($administrable)->isEmpty()
            ? Response::allow()
            : Response::deny('You do not have permission to edit this photo.');
    }

    /**
     * The user may delete (detach) the photo from the children they can administer.
     */
    public function delete(User $user, Photo $photo): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        return $this->administrableChildIds($user, $photo)->isNotEmpty()
            ? Response::allow()
            : Response::deny('You do not have permission to delete this photo.');
    }

    /**
     * A tag may be attached when the user can view the photo, the tag's child is one of the
     * children the photo is linked to, and the user may manage tags in that child
     * (owner/admin, or a member granted upload permission).
     */
    public function attachTag(User $user, Photo $photo, Tag $tag): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        if (! $this->rolesByChild($user, $photo)->has($tag->child_id)) {
            return Response::denyWithStatus(422, 'This tag cannot be attached to this photo.');
        }

        return $user->canUploadTo($tag->child_id)
            ? Response::allow()
            : Response::denyWithStatus(403, 'You do not have permission to manage tags.');
    }

    /**
     * A tag may be detached by anyone who may manage tags in the tag's child, even when the
     * photo is no longer linked to that child (so stale links can still be cleaned up).
     */
    public function detachTag(User $user, Photo $photo, Tag $tag): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        return $user->canUploadTo($tag->child_id)
            ? Response::allow()
            : Response::denyWithStatus(403, 'You do not have permission to manage tags.');
    }

    /**
     * Linking a photo into the target child adds content to it, so it needs the same
     * permission as uploading there (owner/admin, or a member granted upload permission).
     */
    public function share(User $user, Photo $photo, Child $target): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        if (! $user->children()->whereKey($target->id)->exists()) {
            return Response::deny('You are not a member of this child.');
        }

        return $user->canUploadTo($target->id)
            ? Response::allow()
            : Response::deny('You do not have permission to add photos to this child.');
    }

    public function deleteComment(User $user, Photo $photo, Comment $comment): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        if ($comment->user_id === $user->id) {
            return Response::allow();
        }

        $adminChildIds = $this->rolesByChild($user, $photo)
            ->filter(fn (string $role) => in_array($role, ['owner', 'admin'], true))
            ->keys();
        $authorChildIds = $comment->user->children()->pluck('children.id');

        return $adminChildIds->intersect($authorChildIds)->isNotEmpty()
            ? Response::allow()
            : Response::deny('You do not have permission to delete this comment.');
    }
}
