<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Models\UploadIntent;
use App\Services\StorageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:prune-intents')]
#[Description('Delete objects and rows of expired direct-upload intents')]
class PruneUploadIntents extends Command
{
    public function handle(StorageService $storage): int
    {
        $pruned = 0;

        UploadIntent::expired()->each(function (UploadIntent $intent) use ($storage, &$pruned): void {
            // A photo with this uuid means the upload completed; its blobs are live.
            if (Photo::where('uuid', $intent->uuid)->exists()) {
                $intent->delete();

                return;
            }

            try {
                $storage->deleteMultiple([$intent->original_path, $intent->thumbnail_path]);
            } catch (\Throwable $e) {
                $this->warn("Could not delete objects for intent {$intent->uuid}: {$e->getMessage()}");

                return;
            }

            $intent->delete();
            $pruned++;
        });

        $this->info("Pruned {$pruned} expired upload intent(s).");

        return self::SUCCESS;
    }
}
