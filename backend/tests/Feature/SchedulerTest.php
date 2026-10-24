<?php

use Illuminate\Console\Scheduling\Schedule;

describe('scheduled tasks', function () {
    it('schedules sanctum:prune-expired daily', function () {
        $events = collect(app(Schedule::class)->events());

        $pruneEvent = $events->first(
            fn ($event): bool => str_contains($event->command ?? '', 'sanctum:prune-expired'),
        );

        expect($pruneEvent)->not->toBeNull()
            ->and($pruneEvent->expression)->toBe('0 0 * * *');
    });
});
