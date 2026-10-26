<?php

namespace App\Http\Controllers\Api;

use App\Contracts\InviteDeliveryServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Invite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    /**
     * Create a new invite for a child.
     *
     * Generates a shareable invite link. Email is optional in the core —
     * the inviter copies the URL and shares it manually. Email delivery
     * is a cloud-only feature.
     */
    public function store(Request $request, Child $child, InviteDeliveryServiceInterface $inviteDelivery): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:member,admin'],
            'relationship' => ['nullable', 'string', 'max:30'],
            'can_upload' => ['sometimes', 'boolean'],
        ]);

        if ($validated['role'] === 'admin') {
            $callerRole = $request->user()->children()
                ->where('child_id', $child->id)
                ->first()?->pivot->role;

            if ($callerRole !== 'owner') {
                return response()->json(['message' => 'Only the owner can invite an admin.'], 403);
            }
        }

        $invite = Invite::create([
            'child_id' => $child->id,
            'token' => Str::random(64),
            'email' => null,
            'role' => $validated['role'],
            'relationship' => $validated['relationship'] ?? null,
            'can_upload' => $validated['can_upload'] ?? true,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $url = $inviteDelivery->deliver($invite, $child, $request->user());

        return response()->json([
            'data' => [
                'id' => $invite->id,
                'token' => $invite->token,
                'url' => $url,
                'role' => $invite->role,
                'relationship' => $invite->relationship,
                'can_upload' => $invite->can_upload,
                'expires_at' => $invite->expires_at->toISOString(),
                'created_at' => $invite->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Show invite preview details.
     *
     * Returns child name, inviter name, role, and relationship so the invitee
     * can see what they're accepting before logging in. No auth required.
     */
    public function show(string $token): JsonResponse
    {
        $invite = Invite::where('token', $token)
            ->with(['child', 'inviter'])
            ->first();

        if (! $invite) {
            return response()->json([
                'message' => 'Invite not found.',
            ], 404);
        }

        if ($invite->accepted_at) {
            return response()->json([
                'message' => 'This invite has already been accepted.',
                'already_accepted' => [
                    'child_slug' => $invite->child->slug,
                ],
            ], 410);
        }

        if ($invite->revoked_at) {
            return response()->json([
                'message' => 'This invite has been revoked.',
            ], 410);
        }

        if ($invite->expires_at->isPast()) {
            return response()->json([
                'message' => 'This invite has expired.',
            ], 410);
        }

        return response()->json([
            'data' => [
                'token' => $invite->token,
                'child_name' => $invite->child->name,
                'inviter_name' => $invite->inviter->display_name,
                'role' => $invite->role,
                'relationship' => $invite->relationship,
                'can_upload' => $invite->can_upload,
                'expires_at' => $invite->expires_at->toISOString(),
            ],
        ]);
    }

    /**
     * Accept an invite.
     *
     * Creates the child_users membership record and marks the invite as accepted.
     * Returns the child ID so the frontend can navigate to the child dashboard.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invite = Invite::where('token', $token)->first();

        if (! $invite) {
            return response()->json([
                'message' => 'Invite not found.',
            ], 404);
        }

        if ($invite->accepted_at) {
            return response()->json([
                'message' => 'This invite has already been accepted.',
            ], 410);
        }

        if ($invite->revoked_at) {
            return response()->json([
                'message' => 'This invite has been revoked.',
            ], 410);
        }

        if ($invite->expires_at->isPast()) {
            return response()->json([
                'message' => 'This invite has expired.',
            ], 410);
        }

        $user = $request->user();

        $alreadyMember = $user->children()
            ->where('child_id', $invite->child_id)
            ->exists();

        if ($alreadyMember) {
            return response()->json([
                'message' => 'You are already a member of this child.',
                'data' => [
                    'child_id' => $invite->child_id,
                    'child_slug' => $invite->child->slug,
                ],
            ], 200);
        }

        ChildUser::create([
            'child_id' => $invite->child_id,
            'user_id' => $user->id,
            'role' => $invite->role,
            'relationship' => $invite->relationship,
            'can_upload' => $invite->can_upload,
        ]);

        $invite->update([
            'accepted_at' => now(),
            'accepted_by' => $user->id,
        ]);

        return response()->json([
            'data' => [
                'child_id' => $invite->child_id,
                'child_slug' => $invite->child->slug,
                'child_name' => $invite->child->name,
            ],
        ], 200);
    }

    /**
     * List pending invites sent by the current user.
     *
     * Returns invites that haven't been accepted, revoked, or expired.
     */
    public function pending(Request $request): JsonResponse
    {
        $invites = Invite::where('invited_by', $request->user()->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('child')
            ->latest()
            ->get()
            ->map(fn (Invite $invite) => [
                'id' => $invite->id,
                'child_name' => $invite->child->name,
                'role' => $invite->role,
                'relationship' => $invite->relationship,
                'can_upload' => $invite->can_upload,
                'token' => $invite->token,
                'expires_at' => $invite->expires_at->toISOString(),
                'created_at' => $invite->created_at->toISOString(),
            ]);

        return response()->json([
            'data' => $invites,
        ]);
    }

    /**
     * Revoke an invite.
     *
     * Sets the revoked_at timestamp. Only the creator can revoke their own invites,
     * and only if the invite hasn't already been accepted.
     */
    public function destroy(Request $request, Invite $invite): JsonResponse
    {
        if ($invite->invited_by !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only revoke your own invites.',
            ], 403);
        }

        if ($invite->accepted_at) {
            return response()->json([
                'message' => 'Cannot revoke an already accepted invite.',
            ], 422);
        }

        $invite->revoked_at = now();
        $invite->save();

        return response()->json([
            'message' => 'Invite revoked.',
        ]);
    }
}
