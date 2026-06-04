<?php

namespace App\Models;

use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static InviteFactory factory(...$parameters)
 */
class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'token',
        'email',
        'role',
        'relationship',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the child that owns the invite.
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Get the user who created the invite.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
