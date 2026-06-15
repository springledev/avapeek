<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\StorageUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChildController extends Controller
{
    private const AVATAR_PALETTE = [
        '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
        '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9',
    ];

    /**
     * List all children the authenticated user has access to.
     */
    public function index(Request $request): JsonResponse
    {
        $children = $request->user()->children()
            ->withCount(['users as member_count'])
            ->with('storageUsage')
            ->get()
            ->map(function (Child $child) use ($request) {
                $pivot = $child->users->firstWhere('id', $request->user()->id)?->pivot;

                return [
                    'id' => $child->id,
                    'slug' => $child->slug,
                    'name' => $child->name,
                    'birth_date' => $child->birth_date?->toDateString(),
                    'avatar_color' => $child->avatar_color,
                    'age' => $child->ageAt(),
                    'member_count' => $child->member_count,
                    'role' => $pivot?->role,
                    'relationship' => $pivot?->relationship,
                    'storage_used' => (int) ($child->storageUsage?->used_bytes ?? 0),
                    'created_at' => $child->created_at?->toISOString(),
                ];
            });

        return response()->json(['data' => $children]);
    }

    /**
     * Create a new child profile.
     *
     * Creates the child record, assigns the creator as owner,
     * and creates a storage_usage record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date'],
        ]);

        $slug = $this->generateUniqueSlug($validated['name']);

        $child = Child::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'birth_date' => $validated['birth_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $child->avatar_color = self::AVATAR_PALETTE[$child->id % count(self::AVATAR_PALETTE)];
        $child->save();

        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $request->user()->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);

        StorageUsage::create([
            'child_id' => $child->id,
            'used_bytes' => 0,
        ]);

        return response()->json([
            'data' => [
                'id' => $child->id,
                'slug' => $child->slug,
                'name' => $child->name,
                'birth_date' => $child->birth_date?->toDateString(),
                'avatar_color' => $child->avatar_color,
                'age' => $child->ageAt(),
                'member_count' => 1,
                'role' => 'owner',
                'relationship' => 'parent',
                'storage_used' => 0,
                'created_at' => $child->created_at?->toISOString(),
            ],
        ], 201);
    }

    /**
     * Show a single child's details including members and storage usage.
     *
     * Only owners and admins can see the full members list.
     * Regular members only see their own membership info.
     */
    public function show(Request $request, Child $child): JsonResponse
    {
        $currentUser = $request->user();
        $userRole = $currentUser->children()
            ->where('child_id', $child->id)
            ->first()?->pivot->role;

        $child->load([
            'storageUsage',
        ]);

        $members = collect();

        if (in_array($userRole, ['owner', 'admin'], true)) {
            $child->load([
                'users' => function ($query) {
                    $query->select('users.id', 'display_name', 'role', 'relationship');
                },
            ]);

            $members = $child->users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'display_name' => $user->display_name,
                    'role' => $user->pivot->role,
                    'relationship' => $user->pivot->relationship,
                    'joined_at' => $user->pivot->joined_at,
                ];
            });
        }

        return response()->json([
            'data' => [
                'id' => $child->id,
                'slug' => $child->slug,
                'name' => $child->name,
                'birth_date' => $child->birth_date?->toDateString(),
                'avatar_color' => $child->avatar_color,
                'age' => $child->ageAt(),
                'created_by' => $child->created_by,
                'storage_used' => (int) ($child->storageUsage?->used_bytes ?? 0),
                'members' => $members,
                'role' => $userRole,
                'created_at' => $child->created_at?->toISOString(),
            ],
        ]);
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 2;

        while (Child::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
