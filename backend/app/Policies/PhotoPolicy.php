<?php

namespace App\Policies;

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
     * Child ids (linked to the photo) the user may remove the photo from:
     * children where they are owner/admin, or every child they belong to if they uploaded the photo.
     *
     * @return Collection<int, int>
     */
    public function administrableChildIds(User $user, Photo $photo): Collection
    {
        $roles = $this->rolesByChild($user, $photo);

        if ($photo->uploaded_by === $user->id) {
            return $roles->keys()->values();
        }

        return $roles
            ->filter(fn (string $role) => in_array($role, ['owner', 'admin'], true))
            ->keys()
            ->values();
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
     * children the photo is linked to, and the user is a member of that child.
     */
    public function attachTag(User $user, Photo $photo, Tag $tag): Response
    {
        $view = $this->view($user, $photo);
        if ($view->denied()) {
            return $view;
        }

        return $this->rolesByChild($user, $photo)->has($tag->child_id)
            ? Response::allow()
            : Response::deny('This tag cannot be attached to this photo.');
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
