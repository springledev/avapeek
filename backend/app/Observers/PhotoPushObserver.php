<?php

namespace App\Observers;

use App\Models\Photo;
use App\Services\PushBatcher;
use Throwable;

class PhotoPushObserver
{
    public bool $afterCommit = true;

    public function __construct(private PushBatcher $batcher) {}

    public function created(Photo $photo): void
    {
        try {
            $this->batcher->record(PushBatcher::TYPE_PHOTOS, $photo->id, (int) $photo->uploaded_by);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
