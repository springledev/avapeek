<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user, per-child mute flags. The primary key is (user_id, child_id);
 * write through upsert(), never save() on an existing row.
 */
#[Fillable(['user_id', 'child_id', 'notify_photos', 'notify_comments', 'notify_joins', 'notify_admin_comments', 'notify_access_requests', 'notify_access_granted'])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'notify_photos' => 'boolean',
            'notify_comments' => 'boolean',
            'notify_joins' => 'boolean',
            'notify_admin_comments' => 'boolean',
            'notify_access_requests' => 'boolean',
            'notify_access_granted' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }
}
