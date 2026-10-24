<?php

namespace App\Jobs;

use App\Services\PushBatcher;
use App\Services\PushRecipientResolver;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delayed job that closes a batching window for one notification type and
 * sends a single generic push to each resolved recipient subscription.
 */
class SendPushNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $type)
    {
        $this->afterCommit = true;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(PushBatcher $batcher, PushRecipientResolver $resolver, WebPushService $push): void
    {
        if (! $push->isEnabled()) {
            return;
        }

        $events = $batcher->drain($this->type);

        foreach ($resolver->resolve($this->type, $events) as $subscription) {
            $push->send($subscription, $this->type);
        }
    }
}
