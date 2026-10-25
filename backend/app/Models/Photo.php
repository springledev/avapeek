<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static PhotoFactory factory(...$parameters)
 */
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'uploaded_by',
        'original_path',
        'thumbnail_path',
        'lqip_encrypted',
        'iv_lqip',
        'iv_original',
        'iv_thumbnail',
        'original_mime',
        'size_bytes',
        'stored_bytes',
        'width',
        'height',
        'exif_encrypted',
        'iv_exif',
        'name_encrypted',
        'iv_name',
        'taken_at',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
    ];

    /**
     * Limit to photos linked (via child_photo) to a child the user belongs to.
     *
     * @param  Builder<Photo>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $accessibleChildIds = $user->children()->pluck('children.id');

        $query->whereHas('children', function ($q) use ($accessibleChildIds) {
            $q->whereIn('child_photo.child_id', $accessibleChildIds);
        });
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'child_photo')
            ->withPivot('encrypted_pek', 'iv_pek');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'photo_tags')
            ->withPivot('created_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
