<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncryptionKey extends Model
{
    /** Wrapped with the owner's password-derived KEK. */
    public const WRAP_KEK = 'kek';

    /** Wrapped with the owner's RSA public key by another member; awaiting re-wrap. */
    public const WRAP_PUBLIC_KEY = 'public_key';

    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'user_id',
        'encrypted_dek',
        'wrap_type',
        'key_version',
    ];

    /**
     * Get the child that this encryption key belongs to.
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Get the user that this encryption key belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
