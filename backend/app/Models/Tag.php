<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'name',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

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
     * Ids of users who share at least one child with the given user (including themselves).
     *
     * @return array<int, int>
     */
    public static function coMemberIds(User $user): array
    {
        $childIds = $user->children()->pluck('children.id');

        return ChildUser::whereIn('child_id', $childIds)
            ->pluck('user_id')
            ->push($user->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Tags the user may see: created by a co-member, or used on a photo the user can access.
     *
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $childIds = $user->children()->pluck('children.id');

        return $query->where(function (Builder $q) use ($user, $childIds) {
            $q->whereIn('tags.created_by', self::coMemberIds($user))
                ->orWhereHas('photos.children', function ($q2) use ($childIds) {
                    $q2->whereIn('child_photo.child_id', $childIds);
                });
        });
    }

    /**
     * Whether the user may delete this tag: the creator, or an owner/admin of a child shared with the creator.
     */
    public function isDeletableBy(User $user): bool
    {
        if ($this->created_by === $user->id) {
            return true;
        }

        if ($this->created_by === null) {
            return false;
        }

        $adminChildIds = ChildUser::where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('child_id');

        return ChildUser::where('user_id', $this->created_by)
            ->whereIn('child_id', $adminChildIds)
            ->exists();
    }
}
