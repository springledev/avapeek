<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\EncryptionKey;
use App\Models\User;

/**
 * @return array{child: Child, owner: User, member: User}
 */
function albumWithOwnerAndMember(string $memberRole = 'member'): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $owner->id]);

    ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => $memberRole]);

    EncryptionKey::create([
        'child_id' => $child->id,
        'user_id' => $owner->id,
        'encrypted_dek' => 'owner-wrapped-dek',
        'key_version' => 1,
    ]);

    return ['child' => $child, 'owner' => $owner, 'member' => $member];
}

describe('GET /api/encryption-keys', function () {
    it('lists only the user\'s own keys with their wrap type', function () {
        ['child' => $child, 'owner' => $owner, 'member' => $member] = albumWithOwnerAndMember();
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'encrypted_dek' => 'shared',
            'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
            'key_version' => 1,
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/encryption-keys')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.encrypted_dek', 'shared')
            ->assertJsonPath('data.0.wrap_type', EncryptionKey::WRAP_PUBLIC_KEY);
    });
});

describe('GET /api/encryption-keys/pending-grants', function () {
    it('lists members of albums the user administers who have no key yet', function () {
        ['child' => $child, 'owner' => $owner, 'member' => $member] = albumWithOwnerAndMember();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.child_id', $child->id)
            ->assertJsonPath('data.0.user_id', $member->id)
            ->assertJsonPath('data.0.display_name', $member->display_name)
            ->assertJsonPath('data.0.public_key', $member->public_key);
    });

    it('does not leak members of albums the user has no part in', function () {
        ['owner' => $owner, 'member' => $member] = albumWithOwnerAndMember();
        albumWithOwnerAndMember();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $member->id);
    });

    it('does not leak newcomers of an album the user is only a plain member of', function () {
        ['child' => $ownAlbum, 'owner' => $user, 'member' => $ownMember] = albumWithOwnerAndMember();
        ['child' => $otherAlbum] = albumWithOwnerAndMember();
        ChildUser::create(['child_id' => $otherAlbum->id, 'user_id' => $user->id, 'role' => 'member']);
        EncryptionKey::create([
            'child_id' => $otherAlbum->id,
            'user_id' => $user->id,
            'encrypted_dek' => 'own-copy',
            'key_version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.child_id', $ownAlbum->id)
            ->assertJsonPath('data.0.user_id', $ownMember->id);
    });

    it('is empty for a plain member who holds the key while someone else waits', function () {
        ['child' => $child, 'member' => $member] = albumWithOwnerAndMember();
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'encrypted_dek' => 'own-copy',
            'key_version' => 1,
        ]);
        $newcomer = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $newcomer->id, 'role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('is empty for an admin who does not hold the album key', function () {
        ['child' => $child, 'member' => $admin] = albumWithOwnerAndMember('admin');
        $newcomer = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $newcomer->id, 'role' => 'member']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('stops listing a member once declined, without granting a key', function () {
        ['child' => $child, 'owner' => $owner, 'member' => $member] = albumWithOwnerAndMember();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/members/{$member->id}")
            ->assertStatus(200);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseMissing('child_users', ['child_id' => $child->id, 'user_id' => $member->id]);
        $this->assertDatabaseMissing('encryption_keys', ['child_id' => $child->id, 'user_id' => $member->id]);
    });

    it('does not let a plain member or an outsider decline someone', function () {
        ['child' => $child, 'member' => $member] = albumWithOwnerAndMember();
        $newcomer = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $newcomer->id, 'role' => 'member']);
        $outsider = User::factory()->create();

        foreach ([$member, $outsider] as $caller) {
            $this->actingAs($caller, 'sanctum')
                ->deleteJson("/api/children/{$child->id}/members/{$newcomer->id}")
                ->assertStatus(403);
        }

        $this->assertDatabaseHas('child_users', ['child_id' => $child->id, 'user_id' => $newcomer->id]);
    });

    it('is empty for plain members', function () {
        ['member' => $member] = albumWithOwnerAndMember();

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('skips members who already have a key or have no private key yet', function () {
        ['child' => $child, 'owner' => $owner, 'member' => $member] = albumWithOwnerAndMember();
        $legacy = User::factory()->create(['encrypted_private_key' => null]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $legacy->id, 'role' => 'member']);
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'encrypted_dek' => 'shared',
            'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
            'key_version' => 1,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/encryption-keys/pending-grants')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });
});

describe('POST /api/encryption-keys/wrap-for-invitee authorization', function () {
    it('stores the share as public-key wrapped', function () {
        ['child' => $child, 'owner' => $owner, 'member' => $member] = albumWithOwnerAndMember();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $member->id,
                'wrapped_dek' => 'rsa-wrapped',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('encryption_keys', [
            'child_id' => $child->id,
            'user_id' => $member->id,
            'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
        ]);
    });

    it('does not let a plain member share keys', function () {
        ['child' => $child, 'member' => $member] = albumWithOwnerAndMember();
        $newcomer = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $newcomer->id, 'role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $newcomer->id,
                'wrapped_dek' => 'rsa-wrapped',
            ])
            ->assertStatus(403);
    });

    it('does not let an admin overwrite the owner\'s key', function () {
        ['child' => $child, 'owner' => $owner, 'member' => $admin] = albumWithOwnerAndMember('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $owner->id,
                'wrapped_dek' => 'attacker-controlled',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('encryption_keys', [
            'user_id' => $owner->id,
            'encrypted_dek' => 'owner-wrapped-dek',
        ]);
    });

    it('refuses to share with someone outside the album', function () {
        ['child' => $child, 'owner' => $owner] = albumWithOwnerAndMember();
        $outsider = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/encryption-keys/wrap-for-invitee', [
                'child_id' => $child->id,
                'user_id' => $outsider->id,
                'wrapped_dek' => 'rsa-wrapped',
            ])
            ->assertStatus(422);
    });
});

describe('POST /api/encryption-keys', function () {
    it('replaces a shared key with the member\'s own KEK-wrapped copy', function () {
        ['child' => $child, 'member' => $member] = albumWithOwnerAndMember();
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'encrypted_dek' => 'rsa-wrapped',
            'wrap_type' => EncryptionKey::WRAP_PUBLIC_KEY,
            'key_version' => 1,
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/encryption-keys', [
                'child_id' => $child->id,
                'wrapped_dek' => 'kek-wrapped',
            ])
            ->assertStatus(201);

        $keys = EncryptionKey::where('user_id', $member->id)->get();

        expect($keys)->toHaveCount(1);
        expect($keys->first()->wrap_type)->toBe(EncryptionKey::WRAP_KEK);
        expect($keys->first()->encrypted_dek)->toBe('kek-wrapped');
    });

    it('never overwrites an existing KEK-wrapped key', function () {
        ['child' => $child, 'owner' => $owner] = albumWithOwnerAndMember();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/encryption-keys', [
                'child_id' => $child->id,
                'wrapped_dek' => 'replacement',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('encryption_keys', [
            'user_id' => $owner->id,
            'encrypted_dek' => 'owner-wrapped-dek',
        ]);
    });
});
