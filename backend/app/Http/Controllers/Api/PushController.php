<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Services\PushEndpointGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PushController extends Controller
{
    public function config(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json([
            'data' => ['public_key' => config('push.public_key')],
        ]);
    }

    public function subscribe(Request $request, PushEndpointGuard $guard): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushEndpointGuard::MAX_LENGTH, function (string $attribute, mixed $value, \Closure $fail) use ($guard): void {
                if (! is_string($value) || ! $guard->isSafe($value)) {
                    $fail('The endpoint is not an acceptable push service URL.');
                }
            }],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:128', fn (string $a, mixed $v, \Closure $fail) => $this->decodedLength($v) === 65 || $fail('The p256dh key is invalid.')],
            'keys.auth' => ['required', 'string', 'max:64', fn (string $a, mixed $v, \Closure $fail) => $this->decodedLength($v) === 16 || $fail('The auth key is invalid.')],
        ]);

        // Same browser, new login: reassign instead of duplicating, never reveal the old owner.
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($validated['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'failed_attempts' => 0,
            ],
        );

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushEndpointGuard::MAX_LENGTH],
        ]);

        $deleted = PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']))
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        return response()->json(['data' => ['subscribed' => false]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $childIds = $user->children()->orderBy('children.id')->pluck('children.id');

        $preferences = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('child_id', $childIds)
            ->get()
            ->keyBy('child_id');

        return response()->json([
            'data' => $childIds->map(fn (int $childId): array => [
                'child_id' => $childId,
                'notify_photos' => $preferences[$childId]->notify_photos ?? true,
                'notify_comments' => $preferences[$childId]->notify_comments ?? true,
            ])->values(),
        ]);
    }

    public function updatePreferences(Request $request, Child $child): JsonResponse
    {
        $validated = $request->validate([
            'notify_photos' => ['required', 'boolean'],
            'notify_comments' => ['required', 'boolean'],
        ]);

        DB::table('notification_preferences')->upsert(
            [[
                'user_id' => $request->user()->id,
                'child_id' => $child->id,
                'notify_photos' => (bool) $validated['notify_photos'],
                'notify_comments' => (bool) $validated['notify_comments'],
            ]],
            ['user_id', 'child_id'],
            ['notify_photos', 'notify_comments'],
        );

        return response()->json(['data' => [
            'child_id' => $child->id,
            'notify_photos' => (bool) $validated['notify_photos'],
            'notify_comments' => (bool) $validated['notify_comments'],
        ]]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('push.enabled'), 404);
    }

    private function decodedLength(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)) {
            return null;
        }

        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : strlen($decoded);
    }
}
