<?php

namespace App\Services;

use App\Contracts\RegistrationServiceInterface;
use App\Models\Invite;
use App\Models\User;

class DefaultRegistrationService implements RegistrationServiceInterface
{
    public function isRegistrationAllowed(?string $inviteToken = null): bool
    {
        if (User::count() === 0) {
            return true;
        }

        if ($inviteToken === null) {
            return false;
        }

        return Invite::where('token', $inviteToken)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function getRegistrationMode(): string
    {
        return User::count() === 0 ? 'open' : 'invite_only';
    }
}
