<?php

namespace App\Models;

use Database\Factories\UploadIntentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reserved upload slot for a direct-to-storage upload. Holds only keys,
 * sizes, mime and timestamps, never user content.
 *
 * @method static UploadIntentFactory factory(...$parameters)
 */
class UploadIntent extends Model
{
    /** @use HasFactory<UploadIntentFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'original_path',
        'thumbnail_path',
        'original_mime',
        'expected_original_bytes',
        'expected_thumbnail_bytes',
        'reserved_bytes',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'expected_original_bytes' => 'integer',
            'expected_thumbnail_bytes' => 'integer',
            'reserved_bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<UploadIntent>  $query
     */
    public function scopeUnexpired(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<UploadIntent>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('expires_at', '<=', now());
    }
}
