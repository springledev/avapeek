<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\EncryptionKey;
use App\Models\Invite;
use App\Models\User;

describe('POST /api/children/{child}/invite', function () {
    it('creates an invite link and returns the URL', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'member',
                'relationship' => 'Grandparent',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.relationship', 'Grandparent')
            ->assertJsonStructure([
                'data' => ['id', 'token', 'url', 'role', 'relationship', 'expires_at', 'created_at'],
            ]);

        $this->assertDatabaseHas('invites', [
            'child_id' => $child->id,
            'invited_by' => $owner->id,
            'role' => 'member',
            'relationship' => 'Grandparent',
        ]);

        $url = $response->json('data.url');
        $token = $response->json('data.token');
        expect($url)->toContain("/invite/{$token}");
        expect($token)->toHaveLength(64);
    });

    it('sets expires_at to 7 days in the future', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'member',
            ]);

        $expiresAt = $response->json('data.expires_at');
        expect(now()->addDays(7)->diffInSeconds($expiresAt))->toBeLessThan(5);
    });

    it('allows creating an invite without a relationship', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'member',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.relationship', null);
    });

    it('does not require an email', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'member',
            ]);

        $response->assertStatus(201);
    });

    it('validates role is member or admin', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'owner',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->postJson("/api/children/{$child->id}/invite", [
            'role' => 'member',
        ]);

        $response->assertStatus(401);
    });

    it('prevents non-members from creating invites', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'member',
            ]);

        $response->assertStatus(403);
    });

    it('prevents non-admin members from creating invites', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($member, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", [
                'role' => 'member',
            ]);

        $response->assertStatus(403);
    });
});

describe('GET /api/invites/{token}', function () {
    it('returns invite preview details without auth', function () {
        $inviter = User::factory()->create(['display_name' => 'Jane Doe']);
        $child = Child::factory()->create(['name' => 'Emma']);
        $token = str_repeat('a', 64);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $inviter->id,
            'role' => 'admin',
            'relationship' => 'grandparent',
            'token' => $token,
        ]);

        $response = $this->getJson("/api/invites/{$token}");

        $response->assertStatus(200)
            ->assertJsonPath('data.token', $token)
            ->assertJsonPath('data.child_name', 'Emma')
            ->assertJsonPath('data.inviter_name', 'Jane Doe')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.relationship', 'grandparent');
    });

    it('returns 404 for an invalid token', function () {
        $response = $this->getJson('/api/invites/'.str_repeat('z', 64));

        $response->assertStatus(404);
    });

    it('returns 410 for an already-accepted invite', function () {
        $invite = Invite::factory()->accepted()->create();

        $response = $this->getJson("/api/invites/{$invite->token}");

        $response->assertStatus(410)
            ->assertJsonPath('already_accepted.child_slug', $invite->child->slug);
    });

    it('returns 410 for an expired invite', function () {
        $invite = Invite::factory()->expired()->create();

        $response = $this->getJson("/api/invites/{$invite->token}");

        $response->assertStatus(410);
    });
});

describe('POST /api/invites/{token}/accept', function () {
    it('accepts an invite and creates a child_user record', function () {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $inviter->id]);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $inviter->id,
            'role' => 'member',
            'relationship' => 'grandparent',
            'token' => 'accept-me-token',
        ]);

        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson('/api/invites/accept-me-token/accept');

        $response->assertStatus(200)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonPath('data.child_name', $child->name);

        $this->assertDatabaseHas('child_users', [
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'role' => 'member',
            'relationship' => 'grandparent',
        ]);

        $this->assertDatabaseHas('invites', [
            'id' => $invite->id,
            'token' => 'accept-me-token',
        ]);
        $this->assertNotNull($invite->fresh()->accepted_at);
    });

    it('returns 410 for an already-accepted invite', function () {
        $invitee = User::factory()->create();
        $invite = Invite::factory()->accepted()->create();

        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(410);
    });

    it('returns 410 for an expired invite', function () {
        $invitee = User::factory()->create();
        $invite = Invite::factory()->expired()->create();

        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(410);
    });

    it('returns 404 for an invalid token', function () {
        $invitee = User::factory()->create();

        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson('/api/invites/nonexistent/accept');

        $response->assertStatus(404);
    });

    it('requires authentication', function () {
        $invite = Invite::factory()->create();

        $response = $this->postJson("/api/invites/{$invite->token}/accept");

        $response->assertStatus(401);
    });

    it('returns gracefully when the invitee is already a member', function () {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $inviter->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'role' => 'member',
            'relationship' => 'parent',
        ]);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $inviter->id,
            'token' => 'already-member-token',
        ]);

        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson('/api/invites/already-member-token/accept');

        $response->assertStatus(200)
            ->assertJsonPath('data.child_id', $child->id);
    });
});

describe('GET /api/users/{user}/public-key', function () {
    it('returns the public key for a user', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create(['public_key' => 'my-public-key-abc']);

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson("/api/users/{$target->id}/public-key");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.public_key', 'my-public-key-abc');
    });

    it('requires authentication', function () {
        $target = User::factory()->create();

        $response = $this->getJson("/api/users/{$target->id}/public-key");

        $response->assertStatus(401);
    });

    it('returns 404 for a nonexistent user', function () {
        $requester = User::factory()->create();

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson('/api/users/99999/public-key');

        $response->assertStatus(404);
    });
});

describe('POST /api/encryption-keys/wrap-for-invitee', function () {
    it('stores a wrapped DEK for an invitee', function () {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $inviter->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $inviter->id,
            'role' => 'owner',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'role' => 'member',
        ]);

        $wrappedDek = base64_encode(random_bytes(64));

        $response = $this->actingAs($inviter, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $invitee->id,
                'wrapped_dek' => $wrappedDek,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonPath('data.user_id', $invitee->id)
            ->assertJsonPath('data.key_version', 1);

        $this->assertDatabaseHas('encryption_keys', [
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'encrypted_dek' => $wrappedDek,
            'key_version' => 1,
        ]);
    });

    it('refuses to overwrite a key the member already has', function () {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $inviter->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $inviter->id,
            'role' => 'owner',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'role' => 'member',
        ]);

        $oldDek = base64_encode(random_bytes(64));
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'encrypted_dek' => $oldDek,
            'key_version' => 1,
        ]);

        $response = $this->actingAs($inviter, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $invitee->id,
                'wrapped_dek' => base64_encode(random_bytes(64)),
            ]);

        $response->assertStatus(409);

        $this->assertDatabaseHas('encryption_keys', [
            'child_id' => $child->id,
            'user_id' => $invitee->id,
            'encrypted_dek' => $oldDek,
        ]);
    });

    it('prevents non-members from storing a DEK', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $invitee->id,
                'wrapped_dek' => base64_encode(random_bytes(64)),
            ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $response = $this->postJson('/api/encryption-keys/wrap-for-invitee', [
            'child_id' => 1,
            'user_id' => 1,
            'wrapped_dek' => 'test',
        ]);

        $response->assertStatus(401);
    });

    it('validates required fields', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['child_id', 'user_id', 'wrapped_dek']);
    });

    it('validates child_id exists', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => 99999,
                'user_id' => 1,
                'wrapped_dek' => 'test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['child_id']);
    });

    it('validates user_id exists', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => 99999,
                'wrapped_dek' => 'test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    });
});
