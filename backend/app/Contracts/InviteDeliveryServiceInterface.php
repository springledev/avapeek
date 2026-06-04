<?php

namespace App\Contracts;

use App\Models\Child;
use App\Models\Invite;
use App\Models\User;

interface InviteDeliveryServiceInterface
{
    /**
     * Deliver the invite and return the invite URL.
     *
     * In the core, this simply returns the URL for the inviter to copy and share
     * manually. The cloud fork overrides this to also send the invite via email.
     */
    public function deliver(Invite $invite, Child $child, User $inviter): string;
}
