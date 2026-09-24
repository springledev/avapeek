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
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['email', 'password_hash', 'display_name', 'master_key_salt', 'public_key', 'last_seen_at', 'totp_secret_encrypted', 'totp_confirmed_at', 'totp_last_timestep', 'mfa_recovery_codes_hash'])]
#[Hidden(['password_hash', 'remember_token', 'totp_secret_encrypted', 'mfa_recovery_codes_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
            ->withPivot('role', 'relationship', 'joined_at');
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

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebAuthnCredential::class);
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
