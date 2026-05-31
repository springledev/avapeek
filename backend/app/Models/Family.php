<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    protected $fillable = [
        'name',
        'created_by',
        'storage_limit_bytes',
    ];

    /**
     * Get the users that belong to this family.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_users')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Get the encryption keys for this family.
     */
    public function encryptionKeys(): HasMany
    {
        return $this->hasMany(EncryptionKey::class);
    }

    /**
     * Get the albums for this family.
     */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /**
     * Get the photos for this family.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
}
