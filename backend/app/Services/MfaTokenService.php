<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Short-lived, single-use tokens that bind the second login step to the
 * user who passed the password check.
 */
class MfaTokenService
{
    public const TTL_MINUTES = 5;

    private const EXPIRED_MESSAGE = 'Your sign-in step timed out. Please sign in again.';

    /**
     * Create a token for a user who still has to complete MFA.
     */
    public function create(User $user, bool $remember): string
    {
        $nonce = bin2hex(random_bytes(16));

        Cache::put($this->tokenCacheKey($nonce), true, now()->addMinutes(self::TTL_MINUTES));

        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
            'nonce' => $nonce,
            'remember' => $remember,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Validate a token and return the user and session details it is bound to.
     *
     * @return array{user: User, nonce: string, remember: bool}
     *
     * @throws ValidationException
     */
    public function resolve(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->expired();
        }

        if (! is_array($payload) || ! isset($payload['user_id'], $payload['expires_at'], $payload['nonce'])) {
            throw $this->expired();
        }

        if ($payload['expires_at'] < now()->getTimestamp()) {
            throw $this->expired();
        }

        $this->ensureUnused($payload['nonce']);

        $user = User::find($payload['user_id']);

        if ($user === null) {
            throw $this->expired();
        }

        return [
            'user' => $user,
            'nonce' => $payload['nonce'],
            'remember' => (bool) ($payload['remember'] ?? true),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureUnused(string $nonce): void
    {
        if (! Cache::has($this->tokenCacheKey($nonce))) {
            throw $this->expired();
        }
    }

    /**
     * Mark a token as used so it cannot be replayed.
     */
    public function consume(string $nonce): void
    {
        Cache::forget($this->tokenCacheKey($nonce));
    }

    /**
     * Lock held while a challenge for this token is verified, so two
     * concurrent requests cannot both redeem it.
     */
    public function lock(string $nonce): Lock
    {
        return Cache::lock('mfa_token_lock:'.$nonce, 10);
    }

    private function expired(): ValidationException
    {
        return ValidationException::withMessages(['mfa_token' => [self::EXPIRED_MESSAGE]]);
    }

    private function tokenCacheKey(string $nonce): string
    {
        return 'mfa_token:'.$nonce;
    }
}
