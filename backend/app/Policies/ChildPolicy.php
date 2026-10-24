<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ChildPolicy
{
    /**
     * The role the user holds in the child (from child_users), if any.
     */
    private function role(User $user, Child $child): ?string
    {
        $role = $user->children()
            ->where('children.id', $child->id)
            ->first()?->pivot->role;

        return $role === null ? null : (string) $role;
    }

    /**
     * Owners and admins may edit the child's profile.
     */
    public function update(User $user, Child $child): Response
    {
        return in_array($this->role($user, $child), ['owner', 'admin'], true)
            ? Response::allow()
            : Response::deny('You do not have permission to edit this child.');
    }

    /**
     * Only the owner may delete the child.
     */
    public function delete(User $user, Child $child): Response
    {
        return $this->role($user, $child) === 'owner'
            ? Response::allow()
            : Response::deny('Only the owner can delete this child.');
    }
}
