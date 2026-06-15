<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @method static PhotoFactory factory(...$parameters)
 */
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'child_id',
        'uploaded_by',
        'original_path',
        'thumbnail_path',
        'iv_original',
        'iv_thumbnail',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'exif_encrypted',
        'iv_exif',
        'thumbnail_status',
        'taken_at',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
    ];

    /**
     * Get the child that owns the photo.
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Get the user who uploaded the photo.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the tags for this photo.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'photo_tags')
            ->withPivot('created_at');
    }
}
