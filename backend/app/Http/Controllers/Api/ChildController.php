<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ChildController extends Controller
{
    public function __construct(
        protected QuotaService $quotaService,
        protected StorageService $storageService,
    ) {}

    private const AVATAR_PALETTE = [
        '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
        '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9',
    ];

    public function index(Request $request): JsonResponse
    {
        $quota = $this->quotaService->getUsage();

        $children = $request->user()->children()
            ->withCount(['users as member_count', 'photos as photo_count'])
            ->get()
            ->map(function (Child $child) use ($quota) {
                return [
                    'id' => $child->id,
                    'slug' => $child->slug,
                    'name' => $child->name,
                    'birth_date' => $child->birth_date?->toDateString(),
                    'avatar_color' => $child->avatar_color,
                    'age' => $child->ageAt(),
                    'member_count' => $child->member_count,
                    'photo_count' => $child->photo_count,
                    'role' => $child->pivot?->role,
                    'relationship' => $child->pivot?->relationship,
                    'storage_used' => $quota['used_bytes'],
                    'storage_limit_bytes' => $quota['limit_bytes'],
                    'storage_percentage' => $quota['percentage'],
                    'created_at' => $child->created_at?->toISOString(),
                ];
            });

        return response()->json(['data' => $children]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN\s\-.,\'&()]+$/u'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
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

        return response()->json(['data' => $this->formatChild($child, $request->user())], 201);
    }

    public function update(Request $request, Child $child): JsonResponse
    {
        Gate::authorize('update', $child);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN\s\-.,\'&()]+$/u'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $child->update([
            'name' => $validated['name'],
            'birth_date' => $validated['birth_date'] ?? null,
        ]);

        return response()->json(['data' => $this->formatChild($child, $request->user())]);
    }

    public function destroy(Request $request, Child $child): JsonResponse
    {
        Gate::authorize('delete', $child);

        $paths = [];

        DB::transaction(function () use ($child, &$paths) {
            $photoIds = $child->photos()->pluck('photos.id');

            // child_photo, encryption_keys, child_users and invites cascade on the child row.
            $child->delete();

            Photo::whereIn('id', $photoIds)
                ->whereDoesntHave('children')
                ->lockForUpdate()
                ->get()
                ->each(function (Photo $photo) use (&$paths) {
                    array_push($paths, $photo->original_path, $photo->thumbnail_path);

                    $this->quotaService->removeUsage((int) $photo->stored_bytes);

                    $photo->delete();
                });
        });

        if ($paths !== []) {
            $this->storageService->deleteMultiple($paths);
        }

        return response()->json(null, 204);
    }

    public function show(Request $request, Child $child): JsonResponse
    {
        $currentUser = $request->user();
        $userRole = $currentUser->children()
            ->where('child_id', $child->id)
            ->first()?->pivot->role;

        $child->loadCount('photos');

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
                    'last_active' => $user->last_active_at?->toISOString(),
                ];
            });
        }

        $quota = $this->quotaService->getUsage();

        return response()->json([
            'data' => [
                'id' => $child->id,
                'slug' => $child->slug,
                'name' => $child->name,
                'birth_date' => $child->birth_date?->toDateString(),
                'avatar_color' => $child->avatar_color,
                'age' => $child->ageAt(),
                'created_by' => $child->created_by,
                'photo_count' => $child->photos_count,
                'storage_used' => $quota['used_bytes'],
                'storage_limit_bytes' => $quota['limit_bytes'],
                'storage_percentage' => $quota['percentage'],
                'members' => $members,
                'role' => $userRole,
                'created_at' => $child->created_at?->toISOString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatChild(Child $child, User $user): array
    {
        $pivot = $user->children()->where('children.id', $child->id)->first()?->pivot;
        $quota = $this->quotaService->getUsage();

        return [
            'id' => $child->id,
            'slug' => $child->slug,
            'name' => $child->name,
            'birth_date' => $child->birth_date?->toDateString(),
            'avatar_color' => $child->avatar_color,
            'age' => $child->ageAt(),
            'member_count' => $child->users()->count(),
            'photo_count' => $child->photos()->count(),
            'role' => $pivot?->role,
            'relationship' => $pivot?->relationship,
            'storage_used' => $quota['used_bytes'],
            'storage_limit_bytes' => $quota['limit_bytes'],
            'storage_percentage' => $quota['percentage'],
            'created_at' => $child->created_at?->toISOString(),
        ];
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
