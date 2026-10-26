<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\EncryptionKey;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChildMembershipController extends Controller
{
    /**
     * Set a member's role to admin or member. Owner only.
     */
    public function updateRole(Request $request, Child $child, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:admin,member'],
        ]);

        $callerId = $request->user()->id;

        if ($userId === $callerId) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        return DB::transaction(function () use ($child, $callerId, $userId, $validated): JsonResponse {
            Child::whereKey($child->id)->lockForUpdate()->firstOrFail();

            $caller = $this->membership($child->id, $callerId);
            if ($caller?->role !== 'owner') {
                return response()->json(['message' => 'Only the owner can do this.'], 403);
            }

            $target = $this->membership($child->id, $userId);
            if (! $target) {
                return response()->json(['message' => 'User not found in this child'], 404);
            }

            if ($target->role === 'owner') {
                return response()->json(['message' => 'The owner role cannot be changed.'], 403);
            }

            $this->setRole($child->id, $userId, $validated['role']);

            if ($validated['role'] === 'member') {
                $this->revokePendingInvites($child->id, $userId);
            }

            return response()->json(['data' => $this->memberPayload($child->id, $userId)]);
        });
    }

    /**
     * Allow or stop a plain member from uploading photos and managing tags. Owner or admin.
     * Owners and admins can always upload, so their permission is not editable.
     */
    public function updatePermissions(Request $request, Child $child, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'can_upload' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($child, $userId, $validated): JsonResponse {
            Child::whereKey($child->id)->lockForUpdate()->firstOrFail();

            $target = $this->membership($child->id, $userId);
            if (! $target) {
                return response()->json(['message' => 'User not found in this child'], 404);
            }

            if ($target->role !== 'member') {
                return response()->json(['message' => 'Owners and admins can always upload.'], 422);
            }

            ChildUser::where('child_id', $child->id)
                ->where('user_id', $userId)
                ->update(['can_upload' => $validated['can_upload']]);

            return response()->json(['data' => $this->memberPayload($child->id, $userId)]);
        });
    }

    /**
     * Hand ownership to another member; the caller becomes an admin. Owner only.
     */
    public function transferOwnership(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'current_password' => ['required', 'string'],
        ]);

        $caller = $request->user();

        if (! Hash::check($validated['current_password'], $caller->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $targetId = (int) $validated['user_id'];

        if ($targetId === $caller->id) {
            return response()->json(['message' => 'You already own this child.'], 422);
        }

        return DB::transaction(function () use ($child, $caller, $targetId): JsonResponse {
            Child::whereKey($child->id)->lockForUpdate()->firstOrFail();

            $callerRow = $this->membership($child->id, $caller->id);
            if ($callerRow?->role !== 'owner') {
                return response()->json(['message' => 'Only the owner can do this.'], 403);
            }

            if (! $this->membership($child->id, $targetId)) {
                return response()->json(['message' => 'User not found in this child'], 404);
            }

            $this->setRole($child->id, $targetId, 'owner');
            $this->setRole($child->id, $caller->id, 'admin');

            return response()->json(['data' => $this->memberPayload($child->id, $targetId)]);
        });
    }

    /**
     * Leave a child. The owner must transfer ownership first.
     */
    public function leave(Request $request, Child $child): JsonResponse
    {
        $userId = $request->user()->id;

        return DB::transaction(function () use ($child, $userId): JsonResponse {
            Child::whereKey($child->id)->lockForUpdate()->firstOrFail();

            $row = $this->membership($child->id, $userId);
            if (! $row) {
                return response()->json(['message' => 'You are not a member of this child.'], 403);
            }

            if ($row->role === 'owner') {
                return response()->json([
                    'message' => 'The owner cannot leave. Transfer ownership to another member first.',
                ], 409);
            }

            $this->detach($child->id, $userId);

            return response()->json(['message' => 'You have left this child.']);
        });
    }

    /**
     * Remove a member. Admins may remove members; only the owner may remove an admin.
     */
    public function removeMember(Request $request, Child $child, int $userId): JsonResponse
    {
        $callerId = $request->user()->id;

        return DB::transaction(function () use ($child, $callerId, $userId): JsonResponse {
            Child::whereKey($child->id)->lockForUpdate()->firstOrFail();

            $caller = $this->membership($child->id, $callerId);
            if (! in_array($caller?->role, ['owner', 'admin'], true)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $target = $this->membership($child->id, $userId);
            if (! $target) {
                return response()->json(['message' => 'User not found in this child'], 404);
            }

            if ($target->role === 'owner') {
                return response()->json(['message' => 'Cannot remove the owner'], 403);
            }

            if ($target->role === 'admin' && $caller->role !== 'owner') {
                return response()->json(['message' => 'Only the owner can remove an admin.'], 403);
            }

            $this->detach($child->id, $userId);

            return response()->json(['message' => 'Member removed successfully']);
        });
    }

    private function membership(int $childId, int $userId): ?ChildUser
    {
        return ChildUser::where('child_id', $childId)->where('user_id', $userId)->first();
    }

    private function setRole(int $childId, int $userId, string $role): void
    {
        ChildUser::where('child_id', $childId)->where('user_id', $userId)->update(['role' => $role]);
    }

    /**
     * Drop the membership, every wrapped key for it, and any invite links they still hold.
     */
    private function detach(int $childId, int $userId): void
    {
        ChildUser::where('child_id', $childId)->where('user_id', $userId)->delete();

        EncryptionKey::where('child_id', $childId)->where('user_id', $userId)->delete();

        $this->revokePendingInvites($childId, $userId);
    }

    private function revokePendingInvites(int $childId, int $userId): void
    {
        Invite::where('child_id', $childId)
            ->where('invited_by', $userId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * @return array{id: int, display_name: string, role: string, relationship: string|null, can_upload: bool}
     */
    private function memberPayload(int $childId, int $userId): array
    {
        $pivot = $this->membership($childId, $userId);
        $user = User::findOrFail($userId);

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'role' => $pivot->role,
            'relationship' => $pivot->relationship,
            'can_upload' => in_array($pivot->role, ['owner', 'admin'], true) || $pivot->can_upload,
        ];
    }
}
