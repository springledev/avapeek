<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function store(Request $request, Photo $photo): JsonResponse
    {
        $user = $request->user();

        Gate::authorize('view', $photo);

        $validated = $request->validate([
            'body_encrypted' => ['required', 'string', 'max:100000'],
            'iv_body' => ['required', 'string', 'size:32'],
        ]);

        $comment = Comment::create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'body_encrypted' => $validated['body_encrypted'],
            'iv_body' => $validated['iv_body'],
        ]);

        $comment->load('user');

        return response()->json([
            'data' => $this->formatComment($comment),
        ], 201);
    }

    public function index(Request $request, Photo $photo): JsonResponse
    {
        $user = $request->user();

        Gate::authorize('view', $photo);

        $comments = $photo->comments()
            ->with('user:id,display_name')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate(perPage: 50, page: max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => $comments->getCollection()->map(fn (Comment $comment) => $this->formatComment($comment)),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    public function destroy(Request $request, Photo $photo, Comment $comment): JsonResponse
    {
        $user = $request->user();

        Gate::authorize('view', $photo);

        abort_if($comment->photo_id !== $photo->id, 404);

        Gate::authorize('deleteComment', [$photo, $comment]);

        $comment->delete();

        return response()->json(null, 204);
    }

    private function formatComment(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'user_id' => $comment->user_id,
            'author_name' => $comment->user->display_name,
            'body_encrypted' => $comment->body_encrypted,
            'iv_body' => $comment->iv_body,
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}
