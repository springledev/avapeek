<?php

use App\Jobs\SendPushNotifications;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\NotificationPreference;
use App\Models\Photo;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushBatcher;
use App\Services\PushEndpointGuard;
use App\Services\PushRecipientResolver;
use App\Services\WebPushService;
use Database\Factories\PushSubscriptionFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;

const PUSH_PUBLIC_HOST_IP = '93.184.216.34';

beforeEach(function () {
    Storage::fake('s3');
    Cache::flush();
});

function pushEnable(): void
{
    $keys = VAPID::createVapidKeys();
    config([
        'push.enabled' => true,
        'push.public_key' => $keys['publicKey'],
        'push.private_key' => $keys['privateKey'],
        'push.subject' => 'mailto:ops@example.test',
    ]);
}

/**
 * Binds a guard whose DNS answers come from the given map (default: every host is public).
 *
 * @param  array<string, list<string>>  $dns
 */
function pushFakeDns(array $dns = []): void
{
    app()->instance(PushEndpointGuard::class, new PushEndpointGuard(
        fn (string $host): array => $dns[$host] ?? [PUSH_PUBLIC_HOST_IP],
    ));
}

/**
 * @return array{publicKey: string, authToken: string}
 */
function pushRealKeys(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = openssl_pkey_get_details($key)['ec'];
    $point = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

    return [
        'publicKey' => PushSubscriptionFactory::base64Url($point),
        'authToken' => PushSubscriptionFactory::base64Url(random_bytes(16)),
    ];
}

function pushSubscribe(User $user, string $endpoint = 'https://push.example.test/send/abc'): PushSubscription
{
    $keys = pushRealKeys();

    return PushSubscription::factory()->create([
        'user_id' => $user->id,
        'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
        'p256dh' => $keys['publicKey'],
        'auth' => $keys['authToken'],
    ]);
}

function pushMember(Child $child, string $role = 'viewer'): User
{
    $user = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

/**
 * Family with an owner, one other member, one photo linked to the child.
 *
 * @return array{User, Child, Photo, User}
 */
function pushFamily(): array
{
    $owner = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $owner->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);
    $other = pushMember($child);

    $photo = Photo::factory()->create(['uploaded_by' => $owner->id]);
    $photo->children()->attach($child->id, ['encrypted_pek' => bin2hex(random_bytes(32)), 'iv_pek' => bin2hex(random_bytes(16))]);

    return [$owner, $child, $photo, $other];
}

/**
 * @param  array<int, Response>  $responses
 * @param  list<array>  $history
 */
function pushServiceWith(array $responses, array &$history = []): WebPushService
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack, 'http_errors' => false]);

    $service = new WebPushService(app(PushEndpointGuard::class), fn () => $client);
    app()->instance(WebPushService::class, $service);

    return $service;
}

describe('GET /api/push/config', function () {
    it('returns 404 when VAPID keys are unset', function () {
        config(['push.enabled' => false, 'push.public_key' => null]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/push/config')
            ->assertNotFound();
    });

    it('returns only the public key when configured', function () {
        pushEnable();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/push/config')
            ->assertOk()
            ->assertExactJson(['data' => ['public_key' => config('push.public_key')]]);
    });

    it('requires authentication', function () {
        $this->getJson('/api/push/config')->assertUnauthorized();
    });

    it('is disabled by default config without keys', function () {
        expect(config('push.enabled'))->toBeFalse();
    });
});

describe('POST /api/push/subscriptions', function () {
    beforeEach(function () {
        pushEnable();
        pushFakeDns();
    });

    function pushBody(array $overrides = []): array
    {
        $keys = pushRealKeys();

        return array_replace_recursive([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/token-1',
            'keys' => ['p256dh' => $keys['publicKey'], 'auth' => $keys['authToken']],
        ], $overrides);
    }

    it('stores a subscription and never echoes endpoint or keys', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody())
            ->assertCreated()
            ->assertExactJson(['data' => ['subscribed' => true]]);

        $row = PushSubscription::first();
        expect($row->user_id)->toBe($user->id)
            ->and($row->endpoint_hash)->toBe(hash('sha256', 'https://fcm.googleapis.com/fcm/send/token-1'))
            ->and($row->toArray())->not->toHaveKeys(['endpoint', 'p256dh', 'auth']);
    });

    it('upserts when the same endpoint is posted again', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/push/subscriptions', pushBody())->assertCreated();
        $second = pushBody();
        $this->postJson('/api/push/subscriptions', $second)->assertCreated();

        expect(PushSubscription::count())->toBe(1)
            ->and(PushSubscription::first()->p256dh)->toBe($second['keys']['p256dh']);
    });

    it('reassigns an endpoint owned by another user without duplicating it', function () {
        $old = User::factory()->create();
        $new = User::factory()->create();
        pushSubscribe($old, 'https://fcm.googleapis.com/fcm/send/token-1');

        $this->actingAs($new, 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody())
            ->assertCreated()
            ->assertExactJson(['data' => ['subscribed' => true]]);

        expect(PushSubscription::count())->toBe(1)
            ->and(PushSubscription::first()->user_id)->toBe($new->id);
    });

    it('rejects an endpoint that is not acceptable', function (string $endpoint) {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody(['endpoint' => $endpoint]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        expect(PushSubscription::count())->toBe(0);
    })->with([
        'http' => 'http://fcm.googleapis.com/fcm/send/x',
        'localhost' => 'https://localhost/send/x',
        'subdomain of localhost' => 'https://api.localhost/send/x',
        'loopback literal' => 'https://127.0.0.1/send/x',
        'private literal' => 'https://10.0.0.5/send/x',
        'link-local metadata literal' => 'https://169.254.169.254/latest/meta-data',
        'ipv6 loopback' => 'https://[::1]/send/x',
        'ipv4-mapped ipv6' => 'https://[::ffff:127.0.0.1]/send/x',
        'decimal ip' => 'https://2130706433/send/x',
        'credentials' => 'https://user:pass@fcm.googleapis.com/send/x',
        'odd port' => 'https://fcm.googleapis.com:8443/send/x',
        'single label host' => 'https://intranet/send/x',
        'trailing dot' => 'https://host.example.com./send/x',
        'oversized' => 'https://fcm.googleapis.com/'.str_repeat('a', 2100),
    ]);

    it('rejects a hostname resolving to a private address', function () {
        pushFakeDns(['evil.example.test' => ['93.184.216.34', '10.0.0.7']]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody(['endpoint' => 'https://evil.example.test/x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');
    });

    it('rejects a hostname resolving to ipv6 loopback or unique-local', function (string $address) {
        pushFakeDns(['evil.example.test' => [$address]]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody(['endpoint' => 'https://evil.example.test/x']))
            ->assertUnprocessable();
    })->with(['::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1']);

    it('rejects a hostname that does not resolve', function () {
        pushFakeDns(['ghost.example.test' => []]);
        app()->instance(PushEndpointGuard::class, new PushEndpointGuard(fn () => []));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody(['endpoint' => 'https://ghost.example.test/x']))
            ->assertUnprocessable();
    });

    it('rejects malformed keys', function (array $keys) {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/push/subscriptions', ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => $keys])
            ->assertUnprocessable();
    })->with([
        'short p256dh' => [['p256dh' => 'AAAA', 'auth' => PushSubscriptionFactory::base64Url(random_bytes(16))]],
        'not base64url' => [['p256dh' => str_repeat('!', 87), 'auth' => PushSubscriptionFactory::base64Url(random_bytes(16))]],
        'short auth' => [['p256dh' => PushSubscriptionFactory::base64Url(random_bytes(65)), 'auth' => 'AAAA']],
        'missing auth' => [['p256dh' => PushSubscriptionFactory::base64Url(random_bytes(65))]],
    ]);

    it('returns 404 when push is not configured', function () {
        config(['push.enabled' => false]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/push/subscriptions', pushBody())
            ->assertNotFound();
    });

    it('requires authentication', function () {
        $this->postJson('/api/push/subscriptions', pushBody())->assertUnauthorized();
    });
});

describe('DELETE /api/push/subscriptions', function () {
    it('deletes the caller\'s own subscription', function () {
        $user = User::factory()->create();
        pushSubscribe($user, 'https://push.example.test/mine');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/mine'])
            ->assertOk();

        expect(PushSubscription::count())->toBe(0);
    });

    it('returns 404 and leaves a foreign subscription untouched', function () {
        $owner = User::factory()->create();
        pushSubscribe($owner, 'https://push.example.test/theirs');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->deleteJson('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/theirs'])
            ->assertNotFound();

        expect(PushSubscription::count())->toBe(1);
    });

    it('works even when push has been disabled so users can clean up', function () {
        config(['push.enabled' => false]);
        $user = User::factory()->create();
        pushSubscribe($user, 'https://push.example.test/mine');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/mine'])
            ->assertOk();
    });
});

describe('notification preferences', function () {
    it('lists only the caller\'s own children with defaults', function () {
        [$owner, $child] = pushFamily();
        $strangerChild = Child::factory()->create(['created_by' => $owner->id]);
        $member = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'viewer']);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/push/preferences')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['child_id' => $child->id, 'notify_photos' => true, 'notify_comments' => true, 'notify_joins' => true, 'notify_admin_comments' => true],
            ]]);

        expect($strangerChild->id)->not->toBe($child->id);
    });

    it('saves and returns preferences for a member', function () {
        [, $child, , $other] = pushFamily();

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => false, 'notify_comments' => true])
            ->assertOk();
        $this->actingAs($other, 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => false, 'notify_comments' => false])
            ->assertOk();

        expect(NotificationPreference::count())->toBe(1);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/push/preferences')
            ->assertJsonPath('data.0.notify_photos', false)
            ->assertJsonPath('data.0.notify_comments', false);
    });

    it('returns 403 for a non-member', function () {
        [, $child] = pushFamily();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => false, 'notify_comments' => false])
            ->assertForbidden();

        expect(NotificationPreference::count())->toBe(0);
    });

    it('validates the body', function () {
        [, $child, , $other] = pushFamily();

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => 'maybe'])
            ->assertUnprocessable();
    });
});

describe('recipient resolution', function () {
    it('notifies other members only, not the actor, outsiders or removed members', function () {
        [$owner, $child, $photo, $other] = pushFamily();
        $removed = pushMember($child);
        ChildUser::where('user_id', $removed->id)->delete();
        $outsider = User::factory()->create();
        foreach ([$owner, $other, $removed, $outsider] as $user) {
            pushSubscribe($user, 'https://push.example.test/u'.$user->id);
        }

        $ids = app(PushRecipientResolver::class)->resolveUserIds('photos', [[$photo->id, $owner->id]]);

        expect($ids)->toBe([$other->id]);
    });

    it('skips members without subscriptions', function () {
        [$owner, , $photo] = pushFamily();

        expect(app(PushRecipientResolver::class)->resolveUserIds('photos', [[$photo->id, $owner->id]]))->toBe([]);
    });

    it('excludes a muted child or type', function () {
        [$owner, $child, $photo, $other] = pushFamily();
        pushSubscribe($other);
        NotificationPreference::create(['user_id' => $other->id, 'child_id' => $child->id, 'notify_photos' => false, 'notify_comments' => true]);
        $resolver = app(PushRecipientResolver::class);

        expect($resolver->resolveUserIds('photos', [[$photo->id, $owner->id]]))->toBe([])
            ->and($resolver->resolveUserIds('comments', [[$photo->id, $owner->id]]))->toBe([$other->id]);
    });

    it('uses union semantics across shared children', function () {
        [$owner, $child, $photo, $other] = pushFamily();
        $second = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create(['child_id' => $second->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $second->id, 'user_id' => $other->id, 'role' => 'viewer']);
        $photo->children()->attach($second->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);
        pushSubscribe($other);

        NotificationPreference::create(['user_id' => $other->id, 'child_id' => $child->id, 'notify_photos' => false, 'notify_comments' => false]);
        $resolver = app(PushRecipientResolver::class);
        expect($resolver->resolveUserIds('photos', [[$photo->id, $owner->id]]))->toBe([$other->id]);

        NotificationPreference::create(['user_id' => $other->id, 'child_id' => $second->id, 'notify_photos' => false, 'notify_comments' => false]);
        expect($resolver->resolveUserIds('photos', [[$photo->id, $owner->id]]))->toBe([]);
    });

    it('ignores stale preference rows of removed members', function () {
        [$owner, $child, $photo, $other] = pushFamily();
        pushSubscribe($other);
        NotificationPreference::create(['user_id' => $other->id, 'child_id' => $child->id, 'notify_photos' => true, 'notify_comments' => true]);
        ChildUser::where('user_id', $other->id)->delete();

        expect(app(PushRecipientResolver::class)->resolveUserIds('photos', [[$photo->id, $owner->id]]))->toBe([]);
    });

    it('resolves with a constant number of queries', function () {
        $count = function (int $members, int $events): int {
            [$owner, $child] = pushFamily();
            $eventList = [];
            for ($i = 0; $i < $events; $i++) {
                $photo = Photo::factory()->create(['uploaded_by' => $owner->id]);
                $photo->children()->attach($child->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);
                $eventList[] = [$photo->id, $owner->id];
            }
            for ($i = 0; $i < $members; $i++) {
                pushSubscribe(pushMember($child), 'https://push.example.test/q'.uniqid());
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(PushRecipientResolver::class)->resolve('photos', $eventList);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        expect($count(10, 5))->toBe($count(1, 1));
    });
});

describe('dispatch and batching', function () {
    it('schedules one delayed job for five uploads in the window', function () {
        [$owner, $child] = pushFamily();
        pushEnable();
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            $photo = Photo::factory()->create(['uploaded_by' => $owner->id]);
            $photo->children()->attach($child->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);
        }

        Queue::assertPushed(SendPushNotifications::class, 1);
        Queue::assertPushed(SendPushNotifications::class, function (SendPushNotifications $job): bool {
            return $job->type === 'photos' && $job->delay !== null;
        });
    });

    it('batches comments separately from photos', function () {
        [$owner, , $photo] = pushFamily();
        pushEnable();
        Queue::fake();

        Photo::factory()->create(['uploaded_by' => $owner->id]);
        Comment::factory()->count(3)->create(['photo_id' => $photo->id, 'user_id' => $owner->id]);

        Queue::assertPushed(SendPushNotifications::class, 3);
        Queue::assertPushed(SendPushNotifications::class, fn ($job) => $job->type === 'comments');
        Queue::assertPushed(SendPushNotifications::class, fn ($job) => $job->type === 'admin_comments');
        Queue::assertPushed(SendPushNotifications::class, fn ($job) => $job->type === 'photos');
    });

    it('does nothing when push is disabled', function () {
        [$owner] = pushFamily();
        config(['push.enabled' => false]);
        Queue::fake();

        Photo::factory()->create(['uploaded_by' => $owner->id]);

        Queue::assertNothingPushed();
    });

    it('sends one notification per recipient for a whole batch, excluding each actor', function () {
        [$owner, $child, $photo, $other] = pushFamily();
        pushEnable();
        pushFakeDns();
        pushSubscribe($owner, 'https://push.example.test/owner');
        pushSubscribe($other, 'https://push.example.test/other');
        // Owners/admins normally get the dedicated admin_comments push instead of the generic one.
        NotificationPreference::factory()->create(['user_id' => $owner->id, 'child_id' => $child->id, 'notify_admin_comments' => false]);
        $history = [];
        pushServiceWith([new Response(201), new Response(201)], $history);

        $batcher = app(PushBatcher::class);
        Queue::fake();
        $batcher->record('comments', $photo->id, $owner->id);
        $batcher->record('comments', $photo->id, $owner->id);
        $batcher->record('comments', $photo->id, $other->id);
        Queue::assertPushed(SendPushNotifications::class, 1);

        (new SendPushNotifications('comments'))->handle($batcher, app(PushRecipientResolver::class), app(WebPushService::class));

        // owner (via the other member's comment) and other (via the owner's comments): one each.
        expect($history)->toHaveCount(2);

        // The window is closed: a new event schedules a new job.
        $batcher->record('comments', $photo->id, $owner->id);
        Queue::assertPushed(SendPushNotifications::class, 2);
    });

    it('keeps the batch when a job fails so the retry sends one notification per recipient', function () {
        [$owner, , $photo, $other] = pushFamily();
        pushEnable();
        pushFakeDns();
        pushSubscribe($other, 'https://push.example.test/other');
        $history = [];
        pushServiceWith([new Response(201), new Response(201)], $history);
        $batcher = app(PushBatcher::class);
        Queue::fake();
        $batcher->record('photos', $photo->id, $owner->id);
        $batcher->record('photos', $photo->id, $owner->id);

        $failing = Mockery::mock(PushRecipientResolver::class);
        $failing->shouldReceive('resolveUrls')->andReturn([]);
        $failing->shouldReceive('resolve')->once()->andThrow(new RuntimeException('db down'));
        expect(fn () => (new SendPushNotifications('photos'))->handle($batcher, $failing, app(WebPushService::class)))
            ->toThrow(RuntimeException::class);
        expect($history)->toBe([]);

        (new SendPushNotifications('photos'))->handle($batcher, app(PushRecipientResolver::class), app(WebPushService::class));

        expect($history)->toHaveCount(1);

        // A successful run restores nothing: a further run finds an empty batch.
        (new SendPushNotifications('photos'))->handle($batcher, app(PushRecipientResolver::class), app(WebPushService::class));
        expect($history)->toHaveCount(1);
    });

    it('rethrows the original exception even when restoring the batch fails', function () {
        [$owner, , $photo] = pushFamily();
        pushEnable();
        Queue::fake();
        $realBatcher = app(PushBatcher::class);
        $realBatcher->record('photos', $photo->id, $owner->id);

        $batcher = Mockery::mock(PushBatcher::class);
        $batcher->shouldReceive('drain')->once()->andReturn([[$photo->id, $owner->id]]);
        $batcher->shouldReceive('restore')->once()->andThrow(new LogicException('cache down'));
        $resolver = Mockery::mock(PushRecipientResolver::class);
        $resolver->shouldReceive('resolveUrls')->andReturn([]);
        $resolver->shouldReceive('resolve')->once()->andThrow(new RuntimeException('original failure'));

        expect(fn () => (new SendPushNotifications('photos'))->handle($batcher, $resolver, app(WebPushService::class)))
            ->toThrow(RuntimeException::class, 'original failure');
    });

    it('sends nothing when the batch has no remaining recipients', function () {
        [$owner, , $photo, $other] = pushFamily();
        pushEnable();
        pushFakeDns();
        pushSubscribe($other);
        ChildUser::where('user_id', $other->id)->delete();
        $history = [];
        pushServiceWith([], $history);
        $batcher = app(PushBatcher::class);
        Queue::fake();
        $batcher->record('photos', $photo->id, $owner->id);

        (new SendPushNotifications('photos'))->handle($batcher, app(PushRecipientResolver::class), app(WebPushService::class));

        expect($history)->toBe([]);
    });

    it('still creates a comment and an upload when the batcher throws', function () {
        [$owner, $child, $photo] = pushFamily();
        pushEnable();
        $this->mock(PushBatcher::class)->shouldReceive('record')->andThrow(new RuntimeException('queue down'));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", [
                'body_encrypted' => base64_encode(random_bytes(48)),
                'iv_body' => bin2hex(random_bytes(16)),
            ])
            ->assertCreated();

        $this->actingAs($owner, 'sanctum')
            ->post('/api/photos/upload', [
                'file' => UploadedFile::fake()->create('photo.heic', 1),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 1),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/heic',
                'size_bytes' => 1024,
                'children' => json_encode([$child->id => [
                    'encrypted_pek' => bin2hex(random_bytes(32)),
                    'iv_pek' => bin2hex(random_bytes(16)),
                ]]),
            ])
            ->assertCreated();
    });
});

describe('WebPushService', function () {
    beforeEach(function () {
        pushEnable();
        pushFakeDns();
    });

    it('sends a payload of only type and url with low urgency and a 24h TTL', function () {
        [, , , $other] = pushFamily();
        $subscription = pushSubscribe($other);
        $history = [];
        $service = pushServiceWith([new Response(201)], $history);

        expect($service->send($subscription, 'photos'))->toBeTrue();

        $request = $history[0]['request'];
        expect($request->getHeaderLine('TTL'))->toBe('86400')
            ->and($request->getHeaderLine('Urgency'))->toBe('low')
            ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
            ->and((string) $request->getUri())->toBe($subscription->endpoint)
            ->and(WebPushService::payload('photos'))->toBe(['type' => 'photos', 'url' => '/recents'])
            ->and(WebPushService::payload('comments'))->toBe(['type' => 'comments', 'url' => '/recents']);
    });

    it('records success', function () {
        $subscription = pushSubscribe(User::factory()->create());
        $subscription->update(['failed_attempts' => 3]);

        pushServiceWith([new Response(201)])->send($subscription, 'photos');

        $subscription->refresh();
        expect($subscription->failed_attempts)->toBe(0)->and($subscription->last_success_at)->not->toBeNull();
    });

    it('deletes the subscription on 404 or 410', function (int $status) {
        $subscription = pushSubscribe(User::factory()->create());

        expect(pushServiceWith([new Response($status)])->send($subscription, 'photos'))->toBeFalse();

        expect(PushSubscription::count())->toBe(0);
    })->with([404, 410]);

    it('counts 500 responses and deletes at five consecutive failures', function () {
        $subscription = pushSubscribe(User::factory()->create());
        $service = pushServiceWith(array_fill(0, 5, new Response(500)));

        for ($i = 1; $i <= 4; $i++) {
            $service->send($subscription, 'photos');
            expect($subscription->fresh()->failed_attempts)->toBe($i);
        }

        $service->send($subscription->fresh(), 'photos');

        expect(PushSubscription::count())->toBe(0);
    });

    it('re-checks the endpoint right before sending and removes an unsafe one', function () {
        $subscription = pushSubscribe(User::factory()->create(), 'https://rebind.example.test/x');
        pushFakeDns(['rebind.example.test' => ['169.254.169.254']]);
        $history = [];
        $service = pushServiceWith([new Response(201)], $history);

        expect($service->send($subscription, 'photos'))->toBeFalse();

        expect($history)->toBe([])->and(PushSubscription::count())->toBe(0);
    });

    it('skips without penalty when the host cannot be resolved', function () {
        $subscription = pushSubscribe(User::factory()->create());
        app()->instance(PushEndpointGuard::class, new PushEndpointGuard(fn () => []));
        $history = [];
        $service = pushServiceWith([new Response(201)], $history);

        expect($service->send($subscription, 'photos'))->toBeFalse();

        expect($history)->toBe([])->and($subscription->fresh()->failed_attempts)->toBe(0);
    });

    it('sends nothing when push is disabled', function () {
        $subscription = pushSubscribe(User::factory()->create());
        $history = [];
        $service = pushServiceWith([new Response(201)], $history);
        config(['push.enabled' => false]);

        expect($service->send($subscription, 'photos'))->toBeFalse()->and($history)->toBe([]);
    });

    it('builds a client that never follows redirects, times out at 10s and pins DNS', function () {
        $client = WebPushService::pinnedClient('push.example.test', ['93.184.216.34', '2606:2800:220:1::1']);

        expect($client->getConfig('allow_redirects'))->toBeFalse()
            ->and($client->getConfig('timeout'))->toBe(10.0)
            ->and($client->getConfig('curl')[CURLOPT_RESOLVE])->toBe(['push.example.test:443:93.184.216.34,[2606:2800:220:1::1]']);
    });

    it('disables proxies and uses the curl handler so env proxies cannot bypass the pin', function () {
        $client = WebPushService::pinnedClient('push.example.test', ['93.184.216.34']);

        expect($client->getConfig('proxy'))->toBe('');
    });

    it('sets an empty CURLOPT_PROXY even when proxy env vars are present', function () {
        $previous = [getenv('HTTPS_PROXY'), getenv('https_proxy'), getenv('ALL_PROXY')];
        putenv('HTTPS_PROXY=http://127.0.0.1:3128');
        putenv('https_proxy=http://127.0.0.1:3128');
        putenv('ALL_PROXY=http://127.0.0.1:3128');

        try {
            $client = WebPushService::pinnedClient('push.example.test', ['93.184.216.34']);
            $easy = new EasyHandle;
            $easy->request = new Request('POST', 'https://push.example.test/x');
            $easy->options = $client->getConfig();
            $conf = [];
            $apply = new ReflectionMethod(CurlFactory::class, 'applyHandlerOptions');
            $apply->invokeArgs(new CurlFactory(1), [$easy, &$conf]);

            expect(array_key_exists(CURLOPT_PROXY, $conf))->toBeTrue()
                ->and($conf[CURLOPT_PROXY])->toBe('');
        } finally {
            foreach (['HTTPS_PROXY', 'https_proxy', 'ALL_PROXY'] as $i => $name) {
                $value = $previous[$i];
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    });

    it('pins the exact host string used in the request URL', function (string $endpoint, string $pinHost) {
        $guard = new PushEndpointGuard(fn () => ['93.184.216.34']);
        $check = $guard->check($endpoint);
        $client = WebPushService::pinnedClient($check['host'], $check['addresses']);

        expect(strtolower(parse_url($endpoint, PHP_URL_HOST)))->toBe($pinHost)
            ->and($client->getConfig('curl')[CURLOPT_RESOLVE][0])->toStartWith("{$pinHost}:443:");
    })->with([
        ['https://good.example.com/x', 'good.example.com'],
        ['https://GOOD.Example.com/x', 'good.example.com'],
        ['https://good.example.com:443/x', 'good.example.com'],
    ]);

    it('rejects a trailing-dot hostname at send time without sending and removes it', function () {
        $subscription = pushSubscribe(User::factory()->create(), 'https://good.example.com./x');
        $history = [];
        $service = pushServiceWith([new Response(201)], $history);

        expect($service->send($subscription, 'photos'))->toBeFalse();

        expect($history)->toBe([])->and(PushSubscription::count())->toBe(0);
    });

    it('treats a redirect response as a non-followed result', function () {
        $subscription = pushSubscribe(User::factory()->create());
        $history = [];
        pushServiceWith([new Response(302, ['Location' => 'http://127.0.0.1/admin'])], $history)->send($subscription, 'photos');

        expect($history)->toHaveCount(1);
    });
});

describe('maintenance commands', function () {
    it('prunes stale subscriptions and orphaned preferences', function () {
        $user = User::factory()->create();
        $stale = pushSubscribe($user, 'https://push.example.test/stale');
        $stale->forceFill(['last_success_at' => now()->subDays(61)])->save();
        $neverWorked = pushSubscribe($user, 'https://push.example.test/never');
        $neverWorked->forceFill(['created_at' => now()->subDays(61)])->save();
        $fresh = pushSubscribe($user, 'https://push.example.test/fresh');
        $fresh->forceFill(['last_success_at' => now()->subDays(5)])->save();
        $newcomer = pushSubscribe($user, 'https://push.example.test/new');

        [, $child, , $other] = pushFamily();
        NotificationPreference::create(['user_id' => $other->id, 'child_id' => $child->id, 'notify_photos' => false, 'notify_comments' => false]);
        $leaver = pushMember($child);
        NotificationPreference::create(['user_id' => $leaver->id, 'child_id' => $child->id, 'notify_photos' => false, 'notify_comments' => false]);
        ChildUser::where('user_id', $leaver->id)->delete();

        $this->artisan('avapeek:push-prune')->assertSuccessful();

        expect(PushSubscription::pluck('id')->all())->toEqualCanonicalizing([$fresh->id, $newcomer->id])
            ->and(NotificationPreference::pluck('user_id')->all())->toBe([$other->id]);
    });

    it('prints VAPID keys without writing files', function () {
        $this->artisan('avapeek:push-keys')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->assertSuccessful();
    });

    it('schedules avapeek:push-prune daily', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains($e->command ?? '', 'avapeek:push-prune'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');
    });

    it('removes subscriptions when the user is deleted', function () {
        $user = User::factory()->create();
        pushSubscribe($user);

        $user->delete();

        expect(PushSubscription::count())->toBe(0);
    });
});
