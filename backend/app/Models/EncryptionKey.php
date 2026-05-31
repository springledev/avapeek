<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncryptionKey extends Model
{
    protected $fillable = [
        'family_id',
        'user_id',
        'encrypted_dek',
        'key_version',
    ];

    /**
     * Get the family that this encryption key belongs to.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the user that this encryption key belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
