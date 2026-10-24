<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Models\StorageUsage;
use App\Services\StorageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('storage:reconcile {--delete-orphans : Delete bucket objects not referenced by any photo} {--grace=60 : Minutes; objects newer than this are never treated as orphans}')]
#[Description('Recompute storage usage from the database and list (optionally delete) orphaned objects')]
class StorageReconcile extends Command
{
    /** Keys written by PhotoController::upload; nothing else is ever deletable. */
    private const PHOTO_KEY_PATTERN = '#^photos/[0-9a-f-]{36}(\.(jpg|png|webp|heic|heif|bin)|_thumb\.webp)\.enc$#';

    public function handle(StorageService $storage): int
    {
        $grace = (int) $this->option('grace');
        if ($grace < 0) {
            $this->error('--grace must be zero or greater.');

            return self::FAILURE;
        }

        try {
            $actual = (int) Photo::sum('stored_bytes');
            $usage = StorageUsage::global();
            $recorded = (int) $usage->used_bytes;

            if ($recorded !== $actual) {
                $usage->update(['used_bytes' => $actual]);
                $this->info("Usage corrected: {$recorded} -> {$actual} bytes.");
            } else {
                $this->info("Usage already correct: {$actual} bytes.");
            }

            $referenced = [];
            Photo::query()->select(['id', 'original_path', 'thumbnail_path'])->each(function (Photo $photo) use (&$referenced): void {
                $referenced[$photo->original_path] = true;
                $referenced[$photo->thumbnail_path] = true;
            });

            $cutoff = now()->subMinutes($grace)->getTimestamp();
            $orphans = [];
            $unknownAge = [];
            foreach ($storage->listObjects('photos') as $object) {
                if (isset($referenced[$object['path']]) || ! preg_match(self::PHOTO_KEY_PATTERN, $object['path'])) {
                    continue;
                }
                if ($object['last_modified'] === null) {
                    $unknownAge[] = $object['path'];

                    continue;
                }
                if ($object['last_modified'] > $cutoff) {
                    continue;
                }
                $orphans[] = $object;
            }

            foreach ($unknownAge as $path) {
                $this->warn("Skipping {$path}: unknown age.");
            }

            if ($orphans === []) {
                $this->info('No orphaned objects found.');

                return self::SUCCESS;
            }

            $this->warn(count($orphans).' orphaned object(s):');
            $this->table(
                ['Path', 'Size', 'Last modified'],
                array_map(fn (array $o): array => [$o['path'], $o['size'], date('c', $o['last_modified'])], $orphans),
            );

            if (! $this->option('delete-orphans')) {
                $this->line('Re-run with --delete-orphans to delete them.');

                return self::SUCCESS;
            }

            $storage->deleteMultiple(array_column($orphans, 'path'));
            $this->info('Deleted '.count($orphans).' orphaned object(s).');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Reconcile failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
