<?php

use App\Jobs\SendPushNotifications;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Invite;
use App\Models\NotificationPreference;
use App\Models\Photo;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushBatcher;
use App\Services\PushRecipientResolver;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    Cache::flush();
    config(['push.enabled' => true]);
});

function adminPushUser(Child $child, string $role): User
{
    $user = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);
    PushSubscription::factory()->create(['user_id' => $user->id]);

    return $user;
}

function adminPushPhoto(Child ...$children): Photo
{
    $photo = Photo::factory()->create();
    foreach ($children as $child) {
        $photo->children()->attach($child->id, ['encrypted_pek' => bin2hex(random_bytes(32)), 'iv_pek' => bin2hex(random_bytes(16))]);
    }

    return $photo;
}

describe('admin comment notifications', function () {
    it('notifies owners and admins with access but not plain members or the commenter', function () {
        $child = Child::factory()->create();
        $owner = adminPushUser($child, 'owner');
        $admin = adminPushUser($child, 'admin');
        $member = adminPushUser($child, 'member');
        $commenter = adminPushUser($child, 'admin');
        $photo = adminPushPhoto($child);

        $ids = app(PushRecipientResolver::class)->resolveUserIds(PushBatcher::TYPE_ADMIN_COMMENTS, [[$photo->id, $commenter->id]]);

        expect($ids)->toEqualCanonicalizing([$owner->id, $admin->id])
            ->and($ids)->not->toContain($member->id, $commenter->id);
    });

    it('never reaches admins of a child the photo is not linked to', function () {
        $child = Child::factory()->create();
        $otherChild = Child::factory()->create();
        $outsideAdmin = adminPushUser($otherChild, 'owner');
        $insideAdmin = adminPushUser($child, 'owner');
        $photo = adminPushPhoto($child);

        $ids = app(PushRecipientResolver::class)->resolveUserIds(PushBatcher::TYPE_ADMIN_COMMENTS, [[$photo->id, 0]]);

        expect($ids)->toBe([$insideAdmin->id])->and($ids)->not->toContain($outsideAdmin->id);
    });

    it('respects the notify_admin_comments preference per child', function () {
        $child = Child::factory()->create();
        $admin = adminPushUser($child, 'admin');
        $photo = adminPushPhoto($child);
        NotificationPreference::factory()->create(['user_id' => $admin->id, 'child_id' => $child->id, 'notify_admin_comments' => false]);

        $resolver = app(PushRecipientResolver::class);

        expect($resolver->resolveUserIds(PushBatcher::TYPE_ADMIN_COMMENTS, [[$photo->id, 0]]))->toBe([]);
        // Opting out of the admin push falls back to the generic comments push.
        expect($resolver->resolveUserIds(PushBatcher::TYPE_COMMENTS, [[$photo->id, 0]]))->toBe([$admin->id]);
    });

    it('does not send admins the generic comment push as well', function () {
        $child = Child::factory()->create();
        $admin = adminPushUser($child, 'admin');
        $member = adminPushUser($child, 'member');
        $photo = adminPushPhoto($child);

        $ids = app(PushRecipientResolver::class)->resolveUserIds(PushBatcher::TYPE_COMMENTS, [[$photo->id, 0]]);

        expect($ids)->toBe([$member->id])->and($ids)->not->toContain($admin->id);
    });

    it('deep links to the photo for a single photo and to recents for several', function () {
        $child = Child::factory()->create();
        $admin = adminPushUser($child, 'owner');
        $one = adminPushPhoto($child);
        $two = adminPushPhoto($child);
        $resolver = app(PushRecipientResolver::class);

        expect($resolver->resolveUrls(PushBatcher::TYPE_ADMIN_COMMENTS, [[$one->id, 0], [$one->id, 0]]))
            ->toBe([$admin->id => "/recents?photo={$one->id}"])
            ->and($resolver->resolveUrls(PushBatcher::TYPE_ADMIN_COMMENTS, [[$one->id, 0], [$two->id, 0]]))
            ->toBe([$admin->id => '/recents']);
    });

    it('queues an admin_comments job when a comment is created, without comment text in the batch', function () {
        $child = Child::factory()->create();
        adminPushUser($child, 'owner');
        $photo = adminPushPhoto($child);
        Queue::fake();

        $commenter = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $commenter->id, 'role' => 'member']);
        $this->actingAs($commenter, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", ['body_encrypted' => 'deadbeef', 'iv_body' => str_repeat('a', 32)])
            ->assertCreated();

        Queue::assertPushed(SendPushNotifications::class, fn ($job) => $job->type === 'admin_comments');
        expect(json_encode(Cache::get('push:events:admin_comments')))->not->toContain('deadbeef');
    });
});

describe('member joined notifications', function () {
    function joinInvite(Child $child, User $inviter): Invite
    {
        return Invite::create([
            'child_id' => $child->id,
            'token' => str()->random(64),
            'role' => 'member',
            'can_upload' => true,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);
    }

    it('records a joins event when an invite is accepted', function () {
        $child = Child::factory()->create();
        $owner = adminPushUser($child, 'owner');
        $invite = joinInvite($child, $owner);
        Queue::fake();

        $joiner = User::factory()->create();
        $this->actingAs($joiner, 'sanctum')->postJson("/api/invites/{$invite->token}/accept")->assertOk();

        Queue::assertPushed(SendPushNotifications::class, fn ($job) => $job->type === 'joins');
        expect(Cache::get('push:events:joins'))->toBe([[$child->id, $joiner->id]]);
    });

    it('notifies only owners and admins of that child, never the joiner', function () {
        $child = Child::factory()->create();
        $otherChild = Child::factory()->create();
        $owner = adminPushUser($child, 'owner');
        $admin = adminPushUser($child, 'admin');
        $member = adminPushUser($child, 'member');
        $outsider = adminPushUser($otherChild, 'owner');
        $joiner = adminPushUser($child, 'admin');

        $ids = app(PushRecipientResolver::class)->resolveUserIds(PushBatcher::TYPE_JOINS, [[$child->id, $joiner->id]]);

        expect($ids)->toEqualCanonicalizing([$owner->id, $admin->id])
            ->and($ids)->not->toContain($member->id, $outsider->id, $joiner->id);
    });

    it('respects the notify_joins preference and links to the child page', function () {
        $child = Child::factory()->create();
        $owner = adminPushUser($child, 'owner');
        $resolver = app(PushRecipientResolver::class);

        expect($resolver->resolveUrls(PushBatcher::TYPE_JOINS, [[$child->id, 0]]))
            ->toBe([$owner->id => "/settings/children/{$child->id}"]);

        NotificationPreference::factory()->create(['user_id' => $owner->id, 'child_id' => $child->id, 'notify_joins' => false]);

        expect($resolver->resolveUserIds(PushBatcher::TYPE_JOINS, [[$child->id, 0]]))->toBe([]);
    });

    it('does not affect accepting when pushing fails', function () {
        $child = Child::factory()->create();
        $owner = adminPushUser($child, 'owner');
        $invite = joinInvite($child, $owner);
        $this->mock(PushBatcher::class)->shouldReceive('record')->andThrow(new RuntimeException('cache down'));

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/invites/{$invite->token}/accept")
            ->assertOk();
    });
});

describe('payload and preferences', function () {
    it('carries only type and a relative url', function () {
        expect(WebPushService::payload('admin_comments', '/recents?photo=5'))->toBe(['type' => 'admin_comments', 'url' => '/recents?photo=5'])
            ->and(WebPushService::payload('photos'))->toBe(['type' => 'photos', 'url' => '/recents']);
    });

    it('saves the new flags without clobbering unsent ones', function () {
        $child = Child::factory()->create();
        $admin = adminPushUser($child, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => true, 'notify_comments' => true, 'notify_joins' => false, 'notify_admin_comments' => false])
            ->assertOk()
            ->assertJsonPath('data.notify_joins', false)
            ->assertJsonPath('data.notify_admin_comments', false);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => false, 'notify_comments' => true])
            ->assertOk()
            ->assertJsonPath('data.notify_photos', false)
            ->assertJsonPath('data.notify_joins', false);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/push/preferences')
            ->assertJsonPath('data.0.notify_admin_comments', false);
    });

    it('rejects non-boolean flags and non-members', function () {
        $child = Child::factory()->create();
        $admin = adminPushUser($child, 'admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => true, 'notify_comments' => true, 'notify_joins' => 'x'])
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson("/api/push/preferences/{$child->id}", ['notify_photos' => true, 'notify_comments' => true, 'notify_joins' => false])
            ->assertForbidden();
    });
});
