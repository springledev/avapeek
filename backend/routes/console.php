<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('uploads:prune-intents')->everyFifteenMinutes();
Schedule::command('avapeek:push-prune')->daily();

Schedule::command('backup:database')
    ->dailyAt('02:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('backup:verify --quick --allow-empty')
    ->weeklyOn(0, '03:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping();
