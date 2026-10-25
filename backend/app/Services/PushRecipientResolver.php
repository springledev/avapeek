<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves who receives a push at send time, using the same access rule as
 * PhotoPolicy::view (member of any child the photo is linked to). Runs a
 * constant number of queries regardless of how many events or users exist.
 */
class PushRecipientResolver
{
    /**
     * @param  list<array{0: int, 1: int}>  $events  [photo_id, actor_id] pairs.
     * @return Collection<int, PushSubscription> Subscriptions of every user who should be notified.
     */
    public function resolve(string $type, array $events): Collection
    {
        $userIds = $this->resolveUserIds($type, $events);

        if ($userIds === []) {
            return new Collection;
        }

        return PushSubscription::query()->whereIn('user_id', $userIds)->get();
    }

    /**
     * @param  list<array{0: int, 1: int}>  $events
     * @return list<int>
     */
    public function resolveUserIds(string $type, array $events): array
    {
        if ($events === []) {
            return [];
        }

        $column = $type === PushBatcher::TYPE_COMMENTS ? 'notify_comments' : 'notify_photos';
        $photoIds = array_values(array_unique(array_column($events, 0)));

        /** @var array<int, list<int>> $childIdsByPhoto */
        $childIdsByPhoto = [];
        foreach (DB::table('child_photo')->whereIn('photo_id', $photoIds)->get(['photo_id', 'child_id']) as $row) {
            $childIdsByPhoto[(int) $row->photo_id][] = (int) $row->child_id;
        }

        $childIds = array_values(array_unique(array_merge(...array_values($childIdsByPhoto) ?: [[]])));

        if ($childIds === []) {
            return [];
        }

        /** @var array<int, list<int>> $memberIdsByChild */
        $memberIdsByChild = [];
        foreach (DB::table('child_users')->whereIn('child_id', $childIds)->get(['child_id', 'user_id']) as $row) {
            $memberIdsByChild[(int) $row->child_id][] = (int) $row->user_id;
        }

        $memberIds = array_values(array_unique(array_merge(...array_values($memberIdsByChild) ?: [[]])));

        $subscribedUserIds = PushSubscription::query()
            ->whereIn('user_id', $memberIds)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($subscribedUserIds === []) {
            return [];
        }

        /** @var array<string, bool> $muted keyed "user:child" */
        $muted = [];
        $preferences = NotificationPreference::query()
            ->whereIn('child_id', $childIds)
            ->whereIn('user_id', $subscribedUserIds)
            ->where($column, false)
            ->get(['user_id', 'child_id']);
        foreach ($preferences as $preference) {
            $muted[$preference->user_id.':'.$preference->child_id] = true;
        }

        $subscribed = array_flip($subscribedUserIds);
        $recipients = [];

        foreach ($events as [$photoId, $actorId]) {
            foreach ($childIdsByPhoto[$photoId] ?? [] as $childId) {
                foreach ($memberIdsByChild[$childId] ?? [] as $userId) {
                    if ($userId === $actorId || ! isset($subscribed[$userId]) || isset($muted[$userId.':'.$childId])) {
                        continue;
                    }

                    // Union semantics: one unmuted shared child is enough.
                    $recipients[$userId] = true;
                }
            }
        }

        return array_keys($recipients);
    }
}
