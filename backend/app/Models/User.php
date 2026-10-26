<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['email', 'password_hash', 'auth_scheme', 'display_name', 'master_key_salt', 'public_key', 'encrypted_private_key', 'last_seen_at', 'totp_secret_encrypted', 'totp_confirmed_at', 'totp_last_timestep', 'mfa_recovery_codes_hash'])]
#[Hidden(['password_hash', 'remember_token', 'encrypted_private_key', 'totp_secret_encrypted', 'mfa_recovery_codes_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The server stores a bcrypt hash of the plaintext password (accounts from before the auth hash).
     */
    public const AUTH_SCHEME_LEGACY = 'legacy';

    /**
     * The server only ever sees a client-derived auth hash and stores a bcrypt hash of that.
     */
    public const AUTH_SCHEME_V2 = 'v2';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'last_seen_at' => 'datetime',
            'totp_secret_encrypted' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'totp_last_timestep' => 'integer',
            'mfa_recovery_codes_hash' => 'string',
        ];
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'child_users')
            ->withPivot('role', 'relationship', 'can_upload', 'joined_at');
    }

    /**
     * Ids of the children the user may upload photos to and manage tags in:
     * every child where they are owner/admin, plus those where a member was granted `can_upload`.
     *
     * @return Collection<int, int>
     */
    public function uploadableChildIds(): Collection
    {
        return ChildUser::where('user_id', $this->id)
            ->where(fn ($query) => $query
                ->whereIn('role', ['owner', 'admin'])
                ->orWhere('can_upload', true))
            ->pluck('child_id')
            ->map(fn ($id) => (int) $id);
    }

    public function canUploadTo(int $childId): bool
    {
        return $this->uploadableChildIds()->contains($childId);
    }

    public function encryptionKeys(): HasMany
    {
        return $this->hasMany(EncryptionKey::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'uploaded_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebAuthnCredential::class);
    }

    public function usesLegacyAuth(): bool
    {
        return $this->auth_scheme !== self::AUTH_SCHEME_V2;
    }

    public function hasMfaEnabled(): bool
    {
        return $this->totp_confirmed_at !== null || $this->webauthnCredentials()->exists();
    }

    /**
     * @return array<int, string>
     */
    public function enabledMfaMethods(): array
    {
        $methods = [];

        if ($this->totp_confirmed_at !== null) {
            $methods[] = 'totp';
        }

        if ($this->webauthnCredentials()->exists()) {
            $methods[] = 'passkey';
        }

        return $methods;
    }
}
