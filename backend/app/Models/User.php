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

#[Fillable(['email', 'password_hash', 'display_name', 'master_key_salt', 'public_key'])]
#[Hidden(['password_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    /**
     * Get the children that the user belongs to.
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'child_users')
            ->withPivot('role', 'relationship', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Get the encryption keys for the user (wrapped DEKs).
     */
    public function encryptionKeys(): HasMany
    {
        return $this->hasMany(EncryptionKey::class);
    }

    /**
     * Get the photos uploaded by this user.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'uploaded_by');
    }

    /**
     * Get the albums created by this user.
     */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'created_by');
    }

    /**
     * Get the device tokens for this user.
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }
}
