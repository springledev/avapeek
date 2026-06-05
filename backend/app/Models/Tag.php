<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @method static TagFactory factory(...$parameters)
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'name',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Photo::class, 'photo_tags')
            ->withPivot('created_at');
    }
}
