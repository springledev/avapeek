<?php

namespace App\Services;

use App\Models\EncryptionKey;
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
        return array_keys($this->resolveItems($type, $events));
    }

    /**
     * Deep link per recipient user id. A batch touching one photo (or one child)
     * links straight to it; a mixed batch links to the general screen. Only
     * numeric ids ever reach a URL, never names or content.
     *
     * @param  list<array{0: int, 1: int}>  $events
     * @return array<int, string>
     */
    public function resolveUrls(string $type, array $events): array
    {
        $urls = [];

        foreach ($this->resolveItems($type, $events) as $userId => $itemIds) {
            $urls[$userId] = match ($type) {
                PushBatcher::TYPE_JOINS, PushBatcher::TYPE_ACCESS_REQUESTED => count($itemIds) === 1 ? '/settings/children/'.$itemIds[0] : '/settings/children',
                PushBatcher::TYPE_ACCESS_GRANTED => '/feed',
                PushBatcher::TYPE_ADMIN_COMMENTS => count($itemIds) === 1 ? '/recents?photo='.$itemIds[0] : WebPushService::URL,
                default => WebPushService::URL,
            };
        }

        return $urls;
    }

    /**
     * Recipient user id => distinct item ids (photo ids; child ids for joins) that qualified.
     *
     * @param  list<array{0: int, 1: int}>  $events
     * @return array<int, list<int>>
     */
    private function resolveItems(string $type, array $events): array
    {
        if ($events === []) {
            return [];
        }

        $column = match ($type) {
            PushBatcher::TYPE_COMMENTS => 'notify_comments',
            PushBatcher::TYPE_ADMIN_COMMENTS => 'notify_admin_comments',
            PushBatcher::TYPE_JOINS => 'notify_joins',
            PushBatcher::TYPE_ACCESS_REQUESTED => 'notify_access_requests',
            PushBatcher::TYPE_ACCESS_GRANTED => 'notify_access_granted',
            default => 'notify_photos',
        };
        $adminsOnly = in_array($type, [PushBatcher::TYPE_ADMIN_COMMENTS, PushBatcher::TYPE_JOINS, PushBatcher::TYPE_ACCESS_REQUESTED], true);
        $childScoped = in_array($type, [PushBatcher::TYPE_JOINS, PushBatcher::TYPE_ACCESS_REQUESTED, PushBatcher::TYPE_ACCESS_GRANTED], true);

        $itemIds = array_values(array_unique(array_column($events, 0)));

        /** @var array<int, list<int>> $childIdsByItem */
        $childIdsByItem = [];
        if ($childScoped) {
            foreach ($itemIds as $childId) {
                $childIdsByItem[$childId] = [$childId];
            }
        } else {
            foreach (DB::table('child_photo')->whereIn('photo_id', $itemIds)->get(['photo_id', 'child_id']) as $row) {
                $childIdsByItem[(int) $row->photo_id][] = (int) $row->child_id;
            }
        }

        $childIds = array_values(array_unique(array_merge(...array_values($childIdsByItem) ?: [[]])));

        if ($childIds === []) {
            return [];
        }

        /** @var array<int, array<int, string>> $memberRoles child id => [user id => role] */
        $memberRoles = [];
        foreach (DB::table('child_users')->whereIn('child_id', $childIds)->get(['child_id', 'user_id', 'role']) as $row) {
            $memberRoles[(int) $row->child_id][(int) $row->user_id] = (string) $row->role;
        }

        $memberIds = [];
        foreach ($memberRoles as $roles) {
            foreach (array_keys($roles) as $userId) {
                $memberIds[$userId] = true;
            }
        }

        $subscribedUserIds = PushSubscription::query()
            ->whereIn('user_id', array_keys($memberIds))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($subscribedUserIds === []) {
            return [];
        }

        /** @var array<string, bool> $muted keyed "user:child" */
        $muted = [];
        /** @var array<string, bool> $adminCommentsOff keyed "user:child" */
        $adminCommentsOff = [];
        $preferences = NotificationPreference::query()
            ->whereIn('child_id', $childIds)
            ->whereIn('user_id', $subscribedUserIds)
            ->get(['user_id', 'child_id', 'notify_photos', 'notify_comments', 'notify_joins', 'notify_admin_comments', 'notify_access_requests', 'notify_access_granted']);
        foreach ($preferences as $preference) {
            $key = $preference->user_id.':'.$preference->child_id;
            if (! $preference->{$column}) {
                $muted[$key] = true;
            }
            if (! $preference->notify_admin_comments) {
                $adminCommentsOff[$key] = true;
            }
        }

        // Only admins who can actually decrypt the album can act on an access request.
        $keyHolders = [];
        if ($type === PushBatcher::TYPE_ACCESS_REQUESTED) {
            foreach (DB::table('encryption_keys')
                ->whereIn('child_id', $childIds)
                ->whereIn('user_id', $subscribedUserIds)
                ->where('wrap_type', EncryptionKey::WRAP_KEK)
                ->get(['child_id', 'user_id']) as $row) {
                $keyHolders[$row->user_id.':'.$row->child_id] = true;
            }
        }

        $subscribed = array_flip($subscribedUserIds);
        $recipients = [];

        foreach ($events as [$itemId, $actorId]) {
            foreach ($childIdsByItem[$itemId] ?? [] as $childId) {
                foreach ($memberRoles[$childId] ?? [] as $userId => $role) {
                    $key = $userId.':'.$childId;
                    $isAdmin = in_array($role, ['owner', 'admin'], true);

                    $isExcluded = $type === PushBatcher::TYPE_ACCESS_GRANTED ? $userId !== $actorId : $userId === $actorId;

                    if ($isExcluded || ! isset($subscribed[$userId]) || isset($muted[$key])) {
                        continue;
                    }

                    if ($type === PushBatcher::TYPE_ACCESS_REQUESTED && ! isset($keyHolders[$key])) {
                        continue;
                    }

                    if ($adminsOnly && ! $isAdmin) {
                        continue;
                    }

                    // Admins get the more specific admin-comment push instead of the generic one.
                    if ($type === PushBatcher::TYPE_COMMENTS && $isAdmin && ! isset($adminCommentsOff[$key])) {
                        continue;
                    }

                    // Union semantics: one unmuted shared child is enough.
                    $recipients[$userId][$itemId] = $itemId;
                }
            }
        }

        return array_map(fn (array $ids): array => array_values($ids), $recipients);
    }
}
