<?php

namespace App\Console\Commands;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PushPrune extends Command
{
    protected $signature = 'avapeek:push-prune';

    protected $description = 'Delete push subscriptions with no success for 60 days and orphaned notification preferences';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('push.prune_after_days'));

        $subscriptions = PushSubscription::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_success_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('last_success_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();

        $preferences = NotificationPreference::query()
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('child_users')
                    ->whereColumn('child_users.user_id', 'notification_preferences.user_id')
                    ->whereColumn('child_users.child_id', 'notification_preferences.child_id');
            })
            ->delete();

        $this->info("Pruned {$subscriptions} stale subscription(s) and {$preferences} orphaned preference(s).");

        return self::SUCCESS;
    }
}
