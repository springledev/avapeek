<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataService;
use Illuminate\Console\Command;

class DemoClear extends Command
{
    protected $signature = 'avapeek:demo-clear {--force : Do not ask for confirmation}';

    protected $description = 'Remove the demo family, its photos and its storage objects. Real accounts are not touched';

    public function handle(DemoDataService $demoData): int
    {
        $plan = $demoData->clearPlan();

        if ($plan['users'] === 0) {
            $this->info('There is no demo data on this instance. Nothing was changed.');

            return self::SUCCESS;
        }

        if ($plan['blockers'] !== []) {
            $this->error('Real data is tied to the demo data, so nothing was removed:');
            foreach ($plan['blockers'] as $blocker) {
                $this->line("  - {$blocker}");
            }
            $this->line('Move or remove these in the app first, then run this again.');

            return self::FAILURE;
        }

        $children = $plan['children'] === [] ? 'none' : implode(', ', $plan['children']);
        $this->warn('This permanently deletes the demo data only:');
        $this->line("  - {$plan['users']} demo account(s) (@".DemoDataService::EMAIL_DOMAIN.')');
        $this->line("  - children: {$children}");
        $this->line("  - {$plan['photos']} photo(s) and their stored files");

        if ($plan['realAccounts'] > 0) {
            $this->warn("{$plan['realAccounts']} real account(s) on this instance are kept, with their photos.");
        }
        if ($plan['realMembersLosingAccess'] > 0) {
            $this->warn("{$plan['realMembersLosingAccess']} real account(s) joined these demo children and will lose access to them.");
        }

        if (! $this->option('force') && (! $this->input->isInteractive() || ! $this->confirm('Delete this demo data?'))) {
            $this->line('Nothing was changed. Use --force to skip this question in a script.');

            return self::FAILURE;
        }

        $removed = $demoData->clear();

        $this->info("Removed {$removed['users']} demo account(s) and {$removed['photos']} photo(s).");

        return self::SUCCESS;
    }
}
