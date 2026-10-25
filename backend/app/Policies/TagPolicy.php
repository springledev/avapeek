<?php

namespace App\Policies;

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
     * The creator (while still a member), or an owner/admin of the tag's child.
     */
    public function delete(User $user, Tag $tag): Response
    {
        $role = $this->roleInTagChild($user, $tag);

        if ($role === null) {
            return Response::deny('You do not have access to this tag.');
        }

        return ($tag->created_by === $user->id || in_array($role, ['owner', 'admin'], true))
            ? Response::allow()
            : Response::deny('You do not have permission to delete this tag.');
    }
}
