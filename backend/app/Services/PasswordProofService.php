<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks proof that the person at the keyboard knows the account password.
 *
 * The encryption key is derived from the password in the browser, so the server never
 * receives the password itself. A client proves the password with an "auth hash" derived
 * from the key (a one-way function of it), and the server stores a bcrypt hash of that.
 */
class PasswordProofService
{
    /**
     * Auth hashes are lowercase hex SHA-256 digests.
     */
    public const AUTH_HASH_REGEX = '/\A[0-9a-f]{64}\z/';

    private static ?string $dummyHash = null;

    /**
     * Validation rules for endpoints that confirm the current password.
     *
     * When `$changingPassword` is set, `new_auth_hash` is the hash of the new password.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(bool $changingPassword = false): array
    {
        $rules = [
            'current_auth_hash' => ['required', 'string', 'regex:'.self::AUTH_HASH_REGEX],
        ];

        if ($changingPassword) {
            $rules['new_auth_hash'] = ['required', 'string', 'regex:'.self::AUTH_HASH_REGEX];
        }

        return $rules;
    }

    /**
     * Whether the auth hash matches the user, without telling apart a missing user
     * from a wrong secret. One bcrypt check always runs so both take about as long.
     */
    public function matches(?User $user, string $authHash): bool
    {
        $verified = Hash::check($authHash, $user?->password_hash ?? $this->dummyHash());

        return $user !== null && $verified;
    }

    /**
     * Abort with a validation error unless the proof in the validated input is valid.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertValid(User $user, array $validated): void
    {
        if ($this->matches($user, $validated['current_auth_hash'])) {
            return;
        }

        throw ValidationException::withMessages([
            'current_auth_hash' => ['The current password is incorrect.'],
        ]);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}
