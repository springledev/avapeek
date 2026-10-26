<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TagPolicy
{
    private function roleInTagChild(User $user, Tag $tag): ?string
    {
        $role = ChildUser::where('user_id', $user->id)
            ->where('child_id', $tag->child_id)
            ->value('role');

        return $role === null ? null : (string) $role;
    }

    /**
     * Any member of the tag's child may see it.
     */
    public function view(User $user, Tag $tag): Response
    {
        return $this->roleInTagChild($user, $tag) !== null
            ? Response::allow()
            : Response::deny('You do not have access to this tag.');
    }

    /**
     * Owners, admins and members granted upload permission may create tags in the child.
     */
    public function create(User $user, Child $child): Response
    {
        return $user->canUploadTo($child->id)
            ? Response::allow()
            : Response::deny('You do not have permission to manage tags.');
    }

    /**
     * Owners, admins and members granted upload permission may delete the child's tags.
     */
    public function delete(User $user, Tag $tag): Response
    {
        if ($this->roleInTagChild($user, $tag) === null) {
            return Response::deny('You do not have access to this tag.');
        }

        return $user->canUploadTo($tag->child_id)
            ? Response::allow()
            : Response::deny('You do not have permission to delete this tag.');
    }
}
