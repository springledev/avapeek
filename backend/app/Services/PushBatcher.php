<?php

namespace App\Services;

use App\Jobs\SendPushNotifications;
use Illuminate\Support\Facades\Cache;

/**
 * Collects photo/comment events for a short window so each user receives one
 * quiet notification per type. Only the first event of a window schedules the
 * delayed job; later events just append to the pending list. Recipients are
 * NOT resolved here, only when the job runs.
 */
class PushBatcher
{
    public const TYPE_PHOTOS = 'photos';

    public const TYPE_COMMENTS = 'comments';

    /** Pending events outlive a stopped or backlogged worker for an hour. */
    public const EVENTS_TTL_SECONDS = 3600;

    /**
     * @param  int  $photoId  Photo that was added, or that was commented on.
     * @param  int  $actorId  User who uploaded or commented (never notified for this event).
     */
    public function record(string $type, int $photoId, int $actorId): void
    {
        if (! config('push.enabled')) {
            return;
        }

        Cache::lock($this->lockKey($type), 5)->block(3, function () use ($type, $photoId, $actorId): void {
            $events = Cache::get($this->eventsKey($type), []);
            $events[] = [$photoId, $actorId];
            Cache::put($this->eventsKey($type), $events, self::EVENTS_TTL_SECONDS);

            if (Cache::add($this->pendingKey($type), 1, (int) config('push.batch_ttl_seconds'))) {
                SendPushNotifications::dispatch($type)
                    ->delay(now()->addSeconds((int) config('push.batch_delay_seconds')));
            }
        });
    }

    /**
     * Takes and clears the pending events of a type and closes the window.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function drain(string $type): array
    {
        return Cache::lock($this->lockKey($type), 5)->block(3, function () use ($type): array {
            $events = Cache::pull($this->eventsKey($type), []);
            Cache::forget($this->pendingKey($type));

            return $events;
        });
    }

    /**
     * Puts drained events back (ahead of newer ones) after a failed job so a retry still has them.
     *
     * @param  list<array{0: int, 1: int}>  $events
     */
    public function restore(string $type, array $events): void
    {
        if ($events === []) {
            return;
        }

        Cache::lock($this->lockKey($type), 5)->block(3, function () use ($type, $events): void {
            Cache::put($this->eventsKey($type), [...$events, ...Cache::get($this->eventsKey($type), [])], self::EVENTS_TTL_SECONDS);
        });
    }

    private function pendingKey(string $type): string
    {
        return "push:pending:{$type}";
    }

    private function eventsKey(string $type): string
    {
        return "push:events:{$type}";
    }

    private function lockKey(string $type): string
    {
        return "push:lock:{$type}";
    }
}
