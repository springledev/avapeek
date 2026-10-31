<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EncryptionKey;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\PushBatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EncryptionKeysController extends Controller
{
    /**
     * List the authenticated user's wrapped DEKs.
     */
    public function index(Request $request, AuthSessionService $authSession): JsonResponse
    {
        return response()->json([
            'data' => $authSession->encryptionKeysPayload($request->user()),
        ]);
    }

    /**
     * Store a KEK-wrapped DEK for a child.
     *
     * The client generates (or re-wraps a shared) DEK client-side, then
     * uploads the wrapped version. A key shared to the user's public key is
     * replaced by its KEK-wrapped form; an existing KEK-wrapped key is never
     * overwritten.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'wrapped_dek' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $isMember = $user->children()
            ->where('child_id', $validated['child_id'])
            ->exists();

        if (! $isMember) {
            return response()->json([
                'message' => 'You are not a member of this child.',
            ], 403);
        }

        $existing = EncryptionKey::where('child_id', $validated['child_id'])
            ->where('user_id', $user->id)
            ->where('key_version', 1)
            ->first();

        if ($existing !== null && $existing->wrap_type === EncryptionKey::WRAP_KEK) {
            return response()->json([
                'message' => 'A key for this child already exists.',
            ], 409);
        }

        EncryptionKey::updateOrCreate(
            [
                'child_id' => $validated['child_id'],
                'user_id' => $user->id,
                'key_version' => 1,
            ],
            [
                'encrypted_dek' => $validated['wrapped_dek'],
                'wrap_type' => EncryptionKey::WRAP_KEK,
            ]
        );

        return response()->json([
            'data' => [
                'child_id' => $validated['child_id'],
                'wrap_type' => EncryptionKey::WRAP_KEK,
                'key_version' => 1,
            ],
        ], 201);
    }

    /**
     * Return another user's public key.
     *
     * Only the requester themselves or users who share at least one child with
     * the requester are visible. Unrelated and nonexistent users get the same
     * 404 response so the endpoint can't be used to enumerate accounts.
     */
    public function publicKey(Request $request, int $user): JsonResponse
    {
        $requester = $request->user();
        $target = User::find($user);

        if ($target === null || ! $this->sharesChildWith($requester, $target)) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $target->id,
                'public_key' => $target->public_key,
            ],
        ]);
    }

    /**
     * Whether the two users are the same person or share at least one child.
     */
    private function sharesChildWith(User $requester, User $target): bool
    {
        if ($requester->id === $target->id) {
            return true;
        }

        return $requester->children()
            ->whereHas('users', fn ($query) => $query->where('users.id', $target->id))
            ->exists();
    }

    /**
     * Members who joined a child the requester administers but can't see its
     * photos yet because nobody has shared the child's DEK with them.
     */
    public function pendingGrants(Request $request): JsonResponse
    {
        $user = $request->user();

        $administeredChildIds = $user->children()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->whereHas('encryptionKeys', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('wrap_type', EncryptionKey::WRAP_KEK))
            ->pluck('children.id');

        $grants = DB::table('child_users')
            ->join('users', 'users.id', '=', 'child_users.user_id')
            ->leftJoin('encryption_keys', function ($join): void {
                $join->on('encryption_keys.child_id', '=', 'child_users.child_id')
                    ->on('encryption_keys.user_id', '=', 'child_users.user_id');
            })
            ->whereIn('child_users.child_id', $administeredChildIds)
            ->where('child_users.user_id', '!=', $user->id)
            ->whereNotNull('users.encrypted_private_key')
            ->whereNull('encryption_keys.id')
            ->get(['child_users.child_id', 'child_users.user_id', 'users.public_key'])
            ->map(fn (object $row): array => [
                'child_id' => (int) $row->child_id,
                'user_id' => (int) $row->user_id,
                'public_key' => $row->public_key,
            ]);

        return response()->json([
            'data' => $grants,
        ]);
    }

    /**
     * Store a DEK wrapped with a member's public key.
     *
     * Only an owner/admin holding the child's key may share it, only with
     * someone who is already a member, and never over an existing key.
     * The member re-wraps it with their own KEK on their next visit.
     */
    public function wrapForInvitee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'wrapped_dek' => ['required', 'string', 'max:2000'],
        ]);

        $inviter = $request->user();

        $inviterRole = $inviter->children()
            ->where('child_id', $validated['child_id'])
            ->first()?->pivot->role;

        if (! in_array($inviterRole, ['owner', 'admin'], true)) {
            return response()->json([
                'message' => 'Only an album admin can share its key.',
            ], 403);
        }

        $invitee = User::findOrFail($validated['user_id']);

        $inviteeIsMember = $invitee->children()
            ->where('child_id', $validated['child_id'])
            ->exists();

        if (! $inviteeIsMember || $invitee->encrypted_private_key === null) {
            return response()->json([
                'message' => 'This person can\'t receive the album key yet.',
            ], 422);
        }

        $alreadyHasKey = EncryptionKey::where('child_id', $validated['child_id'])
            ->where('user_id', $invitee->id)
            ->exists();

        if ($alreadyHasKey) {
            return response()->json([
                'message' => 'This person already has the album key.',
            ], 409);
        }

        EncryptionKey::create([
            'child_id' => $validated['child_id'],
            'user_id' => $invitee->id,
            'encrypted_dek' => $validated['wrapped_dek'],
            'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
            'key_version' => 1,
        ]);

        try {
            app(PushBatcher::class)->record(PushBatcher::TYPE_ACCESS_GRANTED, $validated['child_id'], $invitee->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => [
                'child_id' => $validated['child_id'],
                'user_id' => $invitee->id,
                'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
                'key_version' => 1,
            ],
        ], 201);
    }
}
