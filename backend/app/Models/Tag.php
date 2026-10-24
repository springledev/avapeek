<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A per-child tag. The name is AES-GCM encrypted client-side with the child's DEK;
 * the server only ever sees `name_encrypted` + `iv_name`.
 *
 * @method static TagFactory factory(...$parameters)
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public const MAX_PER_CHILD = 200;

    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'created_by',
        'name_encrypted',
        'iv_name',
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

    /**
     * Tags belonging to children the user is a member of.
     *
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopeForUserChildren(Builder $query, User $user): Builder
    {
        return $query->whereIn('tags.child_id', $user->children()->select('children.id'));
    }
}
