<?php

namespace App\Contracts;

interface RegistrationServiceInterface
{
    public function isRegistrationAllowed(?string $inviteToken = null): bool;

    public function getRegistrationMode(): string;
}
