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
 *
 * Accounts from before the auth hash (`legacy`) still hold a bcrypt hash of the plaintext
 * password. They can prove it once with the plaintext, and in the same request hand over
 * the auth hash, after which the account is `v2` and plaintext proofs are refused.
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
     * A v2 account sends `current_auth_hash`. A legacy account sends `current_password`
     * together with `new_auth_hash`, which is the auth hash of that same password.
     * When `$changingPassword` is set, `new_auth_hash` is the hash of the new password
     * and is always required.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(bool $changingPassword = false): array
    {
        return [
            'current_auth_hash' => ['required_without:current_password', 'nullable', 'string', 'regex:'.self::AUTH_HASH_REGEX],
            'current_password' => ['required_without:current_auth_hash', 'nullable', 'string', 'max:1024'],
            'new_auth_hash' => [
                $changingPassword ? 'required' : 'required_with:current_password',
                'nullable',
                'string',
                'regex:'.self::AUTH_HASH_REGEX,
            ],
        ];
    }

    /**
     * Whether the credentials match the user, without telling apart a missing user,
     * a wrong secret or the wrong scheme. One bcrypt check always runs so these cases
     * take about as long as each other.
     */
    public function matches(?User $user, ?string $authHash, ?string $password): bool
    {
        $candidate = match (true) {
            $user === null => null,
            $user->usesLegacyAuth() => $password,
            default => $authHash,
        };

        $stored = $user?->password_hash ?? $this->dummyHash();
        $verified = Hash::check($candidate ?? '', $stored);

        return $user !== null && $candidate !== null && $verified;
    }

    /**
     * Store the auth hash as the account's password and mark the account as v2.
     */
    public function upgrade(User $user, string $newAuthHash): void
    {
        $user->forceFill([
            'password_hash' => Hash::make($newAuthHash),
            'auth_scheme' => User::AUTH_SCHEME_V2,
        ])->save();
    }

    /**
     * Abort with a validation error unless the proof in the validated input is valid.
     *
     * With `$upgrade` set (the default), a valid legacy proof also moves the account to v2.
     * Pass false when the caller is about to set a new password itself.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertValid(User $user, array $validated, bool $upgrade = true): void
    {
        $authHash = $validated['current_auth_hash'] ?? null;
        $password = $validated['current_password'] ?? null;

        if ($this->matches($user, $authHash, $password)) {
            if ($upgrade && $user->usesLegacyAuth()) {
                $this->upgrade($user, $validated['new_auth_hash']);
            }

            return;
        }

        $message = ! $user->usesLegacyAuth() && $authHash === null
            ? 'This account was upgraded on another device. Sign out and back in, then try again.'
            : 'The current password is incorrect.';

        throw ValidationException::withMessages([
            'current_password' => [$message],
        ]);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}
