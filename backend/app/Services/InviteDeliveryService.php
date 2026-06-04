<?php

namespace App\Services;

use App\Contracts\InviteDeliveryServiceInterface;
use App\Models\Child;
use App\Models\Invite;
use App\Models\User;

class InviteDeliveryService implements InviteDeliveryServiceInterface
{
    /**
     * Return the invite URL without sending an email.
     *
     * Email delivery is a cloud-only feature. The core just returns the URL
     * so the inviter can copy and share it manually via any channel.
     */
    public function deliver(Invite $invite, Child $child, User $inviter): string
    {
        return config('app.url').'/invite/'.$invite->token;
    }
}
