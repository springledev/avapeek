<?php

namespace App\Observers;

use App\Models\Comment;
use App\Services\PushBatcher;
use Throwable;

class CommentPushObserver
{
    public bool $afterCommit = true;

    public function __construct(private PushBatcher $batcher) {}

    public function created(Comment $comment): void
    {
        try {
            $this->batcher->record(PushBatcher::TYPE_COMMENTS, $comment->photo_id, (int) $comment->user_id);
            $this->batcher->record(PushBatcher::TYPE_ADMIN_COMMENTS, $comment->photo_id, (int) $comment->user_id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
