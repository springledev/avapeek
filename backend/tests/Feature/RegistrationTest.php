<?php

use App\Models\Child;
use App\Models\Invite;
use App\Models\User;

describe('GET /api/auth/registration-mode', function () {
    it('returns open when no users exist', function () {
        $response = $this->getJson('/api/auth/registration-mode');

        $response->assertStatus(200)
            ->assertJsonPath('data.mode', 'open');
    });

    it('returns invite_only when at least one user exists', function () {
        User::factory()->create();

        $response = $this->getJson('/api/auth/registration-mode');

        $response->assertStatus(200)
            ->assertJsonPath('data.mode', 'invite_only');
    });
});

describe('POST /api/auth/register — first user bootstrap', function () {
    it('allows the first user to register without an invite token', function () {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'first@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'First User',
            'master_key_salt' => str_repeat('a', 64),
            'public_key' => 'test-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'first@example.com')
            ->assertJsonPath('user.display_name', 'First User')
            ->assertJsonPath('user.master_key_salt', str_repeat('a', 64))
            ->assertJsonPath('user.public_key', 'test-public-key')
            ->assertJsonPath('accepted_child', null)
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', [
            'email' => 'first@example.com',
            'display_name' => 'First User',
            'master_key_salt' => str_repeat('a', 64),
            'public_key' => 'test-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
        ]);
    });
});

describe('POST /api/auth/register — invite-only mode', function () {
    it('returns 403 without an invite token when users exist', function () {
        User::factory()->create();

        $response = $this->postJson('/api/auth/register', [
            'email' => 'new@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'New User',
            'master_key_salt' => str_repeat('a', 64),
            'public_key' => 'test-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Registration is by invitation only.');
    });

    it('succeeds with a valid invite token', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $owner->id,
            'token' => str_repeat('a', 64),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'invited@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Invited User',
            'master_key_salt' => str_repeat('b', 64),
            'public_key' => 'invited-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'invited@example.com')
            ->assertJsonPath('accepted_child.child_id', $child->id)
            ->assertJsonPath('accepted_child.child_name', $child->name)
            ->assertJsonPath('accepted_child.child_slug', $child->slug);
    });

    it('returns 403 with an already-accepted invite token', function () {
        $invite = Invite::factory()->accepted()->create();

        $response = $this->postJson('/api/auth/register', [
            'email' => 'accepted@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Accepted User',
            'master_key_salt' => str_repeat('c', 64),
            'public_key' => 'accepted-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(403);
    });

    it('returns 403 with a revoked invite token', function () {
        $invite = Invite::factory()->create([
            'revoked_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'revoked@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Revoked User',
            'master_key_salt' => str_repeat('d', 64),
            'public_key' => 'revoked-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(403);
    });

    it('returns 403 with an expired invite token', function () {
        $invite = Invite::factory()->expired()->create();

        $response = $this->postJson('/api/auth/register', [
            'email' => 'expired@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Expired User',
            'master_key_salt' => str_repeat('e', 64),
            'public_key' => 'expired-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(403);
    });

    it('returns 403 with an invalid or nonexistent invite token', function () {
        User::factory()->create();

        $response = $this->postJson('/api/auth/register', [
            'email' => 'invalid@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Invalid User',
            'master_key_salt' => str_repeat('f', 64),
            'public_key' => 'invalid-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => str_repeat('z', 64),
        ]);

        $response->assertStatus(403);
    });
});

describe('POST /api/auth/register — invite acceptance side effects', function () {
    it('creates a child_user pivot record with the correct role and relationship', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $owner->id,
            'role' => 'admin',
            'relationship' => 'grandparent',
            'token' => str_repeat('x', 64),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'sideeffect@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Side Effect User',
            'master_key_salt' => str_repeat('0', 64),
            'public_key' => 'sideeffect-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(201);

        $userId = $response->json('user.id');

        $this->assertDatabaseHas('child_users', [
            'child_id' => $child->id,
            'user_id' => $userId,
            'role' => 'admin',
            'relationship' => 'grandparent',
        ]);
    });

    it('sets accepted_at and accepted_by on the invite', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $owner->id,
            'token' => str_repeat('y', 64),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'acceptset@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Accept Set User',
            'master_key_salt' => str_repeat('1', 64),
            'public_key' => 'acceptset-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(201);

        $userId = $response->json('user.id');

        $this->assertDatabaseHas('invites', [
            'id' => $invite->id,
            'accepted_by' => $userId,
        ]);

        expect($invite->fresh()->accepted_at)->not->toBeNull();
    });

    it('returns accepted_child in the registration response', function () {
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        $invite = Invite::factory()->create([
            'child_id' => $child->id,
            'invited_by' => $owner->id,
            'role' => 'member',
            'relationship' => 'aunt',
            'token' => str_repeat('r', 64),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'returnchild@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'Return Child User',
            'master_key_salt' => str_repeat('2', 64),
            'public_key' => 'returnchild-public-key',
            'encrypted_private_key' => 'wrapped-private-key',
            'invite_token' => $invite->token,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('accepted_child.child_id', $child->id)
            ->assertJsonPath('accepted_child.child_name', $child->name)
            ->assertJsonPath('accepted_child.child_slug', $child->slug);
    });
});
