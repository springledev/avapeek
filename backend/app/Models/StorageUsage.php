<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageUsage extends Model
{
    protected $table = 'storage_usage';

    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'used_bytes',
    ];

    /**
     * Get the child that owns this storage usage record.
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }
}
