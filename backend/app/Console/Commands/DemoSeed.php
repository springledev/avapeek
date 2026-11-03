<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataService;
use Illuminate\Console\Command;

class DemoSeed extends Command
{
    protected $signature = 'avapeek:demo-seed {--photos=36 : Number of photos to create}';

    protected $description = 'Fill an empty instance with a made-up family to try Avapeek out (undo with avapeek:demo-clear)';

    public function handle(DemoDataService $demoData): int
    {
        $photoCount = (int) $this->option('photos');
        if ($photoCount < 0) {
            $this->error('--photos cannot be negative.');

            return self::INVALID;
        }

        $this->info("Creating the demo family and {$photoCount} photo(s)...");

        try {
            $accounts = $demoData->seed($photoCount);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Email', 'Name', 'Access'], $accounts);
        $this->line('Password for every account: '.DemoDataService::PASSWORD);
        $this->warn('Anyone who can reach this instance can sign in with these accounts. Do not leave demo data on a public server.');
        $this->line('When you are done: php artisan avapeek:demo-clear (then register your real first account).');

        return self::SUCCESS;
    }
}
