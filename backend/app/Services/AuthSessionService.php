<?php

namespace App\Services;

use App\Models\EncryptionKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class AuthSessionService
{
    /**
     * Lifetime of a token issued without "keep me signed in".
     */
    public const SHORT_SESSION_HOURS = 12;

    /**
     * Issue an API token. Tokens for sessions the user did not ask to keep
     * expire server-side, so closing the tab is not the only safeguard.
     */
    public function issueToken(User $user, bool $remember): string
    {
        $expiresAt = $remember ? null : now()->addHours(self::SHORT_SESSION_HOURS);

        return $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
    }

    /**
     * Replace the current token with a new one of the same kind (short or remembered).
     */
    public function refreshToken(User $user): string
    {
        $current = $user->currentAccessToken();
        $remember = true;

        if ($current instanceof PersonalAccessToken) {
            $remember = $current->expires_at === null;
            $current->delete();
        }

        return $this->issueToken($user, $remember);
    }

    /**
     * Build the full signed-in response: user, token and wrapped DEKs.
     */
    public function loginResponse(User $user, bool $remember): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $this->issueToken($user, $remember),
            'encryption_keys' => $this->encryptionKeysPayload($user),
        ]);
    }

    /**
     * @return array{id: int, email: string, display_name: string, master_key_salt: string, auth_scheme: string, public_key: string, encrypted_private_key: string|null}
     */
    public function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'master_key_salt' => $user->master_key_salt,
            'auth_scheme' => $user->usesLegacyAuth() ? User::AUTH_SCHEME_LEGACY : User::AUTH_SCHEME_V2,
            'public_key' => $user->public_key,
            'encrypted_private_key' => $user->encrypted_private_key,
        ];
    }

    /**
     * @return array<int, array{child_id: int, child_name: string|null, encrypted_dek: string, wrap_type: string, key_version: int}>
     */
    public function encryptionKeysPayload(User $user): array
    {
        return $user->encryptionKeys()
            ->with('child')
            ->get()
            ->map(fn (EncryptionKey $key): array => [
                'child_id' => $key->child_id,
                'child_name' => $key->child->name ?? null,
                'encrypted_dek' => $key->encrypted_dek,
                'wrap_type' => $key->wrap_type,
                'key_version' => $key->key_version,
            ])
            ->all();
    }
}
