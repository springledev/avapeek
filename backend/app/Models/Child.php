<?php

namespace App\Models;

use Database\Factories\ChildFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @use HasFactory<ChildFactory> */
class Child extends Model
{
    /** @use HasFactory<ChildFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'birth_date',
        'avatar_color',
        'created_by',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'child_users')
            ->withPivot('role', 'relationship', 'joined_at');
    }

    public function encryptionKeys(): HasMany
    {
        return $this->hasMany(EncryptionKey::class);
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Photo::class, 'child_photo')
            ->withPivot('encrypted_pek', 'iv_pek');
    }

    public function storageUsage(): HasOne
    {
        return $this->hasOne(StorageUsage::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    public function ageAt(?\DateTime $date = null): ?string
    {
        if (! $this->birth_date) {
            return null;
        }

        $date = $date ?? now();
        $diff = $this->birth_date->diff($date);

        if ($diff->y === 0 && $diff->m === 0) {
            return "{$diff->d} days old";
        }

        if ($diff->y === 0) {
            return "{$diff->m} month".($diff->m > 1 ? 's' : '').' old';
        }

        if ($diff->y < 5) {
            return "{$diff->y} year".($diff->y > 1 ? 's' : '').", {$diff->m} month".($diff->m > 1 ? 's' : '');
        }

        return "{$diff->y} years old";
    }
}
