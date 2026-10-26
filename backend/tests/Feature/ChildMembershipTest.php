<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\EncryptionKey;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Storage;

function familyMember(string $role, Child $child): User
{
    $user = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function giveKeys(Child $child, User $user): void
{
    foreach ([1, 2, 3] as $version) {
        EncryptionKey::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'encrypted_dek' => "dek-{$user->id}-{$version}",
            'wrap_type' => $version === 2 ? 'public_key' : 'kek',
            'key_version' => $version,
        ]);
    }
}

function roleOf(Child $child, User $user): ?string
{
    return ChildUser::where('child_id', $child->id)->where('user_id', $user->id)->value('role');
}

function ownerCount(Child $child): int
{
    return ChildUser::where('child_id', $child->id)->where('role', 'owner')->count();
}

beforeEach(function () {
    Storage::fake('s3');
    $this->child = Child::factory()->create();
    $this->owner = familyMember('owner', $this->child);
    $this->admin = familyMember('admin', $this->child);
    $this->member = familyMember('member', $this->child);
    $this->stranger = User::factory()->create();
});

describe('PATCH /api/children/{child}/members/{user}', function () {
    it('lets the owner promote and demote', function () {
        $url = "/api/children/{$this->child->id}/members/{$this->member->id}";

        $this->actingAs($this->owner, 'sanctum')->patchJson($url, ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.id', $this->member->id)
            ->assertJsonStructure(['data' => ['id', 'display_name', 'role', 'relationship']]);
        expect(roleOf($this->child, $this->member))->toBe('admin');

        $this->actingAs($this->owner, 'sanctum')->patchJson($url, ['role' => 'member'])->assertOk();
        expect(roleOf($this->child, $this->member))->toBe('member');
    });

    it('forbids admins and members', function () {
        $url = "/api/children/{$this->child->id}/members/{$this->member->id}";

        $this->actingAs($this->admin, 'sanctum')->patchJson($url, ['role' => 'admin'])->assertForbidden();
        $this->actingAs($this->member, 'sanctum')->patchJson($url, ['role' => 'admin'])->assertForbidden();
        expect(roleOf($this->child, $this->member))->toBe('member');
    });

    it('rejects self, an owner role in the body, and unknown roles', function () {
        $base = "/api/children/{$this->child->id}/members";

        $this->actingAs($this->owner, 'sanctum')->patchJson("{$base}/{$this->owner->id}", ['role' => 'member'])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->patchJson("{$base}/{$this->member->id}", ['role' => 'owner'])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->patchJson("{$base}/{$this->member->id}", ['role' => 'root'])
            ->assertStatus(422);
        expect(roleOf($this->child, $this->owner))->toBe('owner');
        expect(roleOf($this->child, $this->member))->toBe('member');
    });

    it('returns 404 for a user from another child', function () {
        $other = Child::factory()->create();
        $outsider = familyMember('member', $other);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/children/{$this->child->id}/members/{$outsider->id}", ['role' => 'admin'])
            ->assertNotFound();
        expect(roleOf($other, $outsider))->toBe('member');
    });

    it('forbids non-members', function () {
        $url = "/api/children/{$this->child->id}/members/{$this->member->id}";

        $this->actingAs($this->stranger, 'sanctum')->patchJson($url, ['role' => 'admin'])->assertForbidden();
    });

    it('requires auth', function () {
        $this->patchJson("/api/children/{$this->child->id}/members/{$this->member->id}", ['role' => 'admin'])
            ->assertUnauthorized();
    });

    it('revokes pending invites of a demoted admin', function () {
        $invite = Invite::factory()->create([
            'child_id' => $this->child->id, 'invited_by' => $this->admin->id, 'role' => 'admin',
        ]);
        $other = Invite::factory()->create(['child_id' => $this->child->id, 'invited_by' => $this->owner->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/children/{$this->child->id}/members/{$this->admin->id}", ['role' => 'member'])
            ->assertOk();

        expect($invite->fresh()->revoked_at)->not->toBeNull();
        expect($other->fresh()->revoked_at)->toBeNull();
    });
});

describe('POST /api/children/{child}/transfer-ownership', function () {
    it('swaps owner and admin and leaves exactly one owner', function () {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/transfer-ownership", [
                'user_id' => $this->member->id,
                'current_auth_hash' => UserFactory::AUTH_HASH,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');

        expect(roleOf($this->child, $this->member))->toBe('owner');
        expect(roleOf($this->child, $this->owner))->toBe('admin');
        expect(roleOf($this->child, $this->admin))->toBe('admin');
        expect(ownerCount($this->child))->toBe(1);
    });

    it('requires the correct password', function () {
        $url = "/api/children/{$this->child->id}/transfer-ownership";

        $this->actingAs($this->owner, 'sanctum')->postJson($url, ['user_id' => $this->member->id])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($url, ['user_id' => $this->member->id, 'current_auth_hash' => str_repeat('f', 64)])
            ->assertStatus(422)->assertJsonValidationErrors('current_auth_hash');
        expect(roleOf($this->child, $this->owner))->toBe('owner');
    });

    it('rejects self-transfer and non-member targets', function () {
        $url = "/api/children/{$this->child->id}/transfer-ownership";

        $this->actingAs($this->owner, 'sanctum')
            ->postJson($url, ['user_id' => $this->owner->id, 'current_auth_hash' => UserFactory::AUTH_HASH])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson($url, ['user_id' => $this->stranger->id, 'current_auth_hash' => UserFactory::AUTH_HASH])
            ->assertNotFound();
        expect(ownerCount($this->child))->toBe(1);
    });

    it('cannot target a member of another child', function () {
        $other = Child::factory()->create();
        $outsider = familyMember('member', $other);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/transfer-ownership", [
                'user_id' => $outsider->id, 'current_auth_hash' => UserFactory::AUTH_HASH,
            ])->assertNotFound();
        expect(roleOf($other, $outsider))->toBe('member');
        expect(roleOf($this->child, $this->owner))->toBe('owner');
    });

    it('forbids admins, members and non-members', function () {
        $url = "/api/children/{$this->child->id}/transfer-ownership";
        $body = ['user_id' => $this->member->id, 'current_auth_hash' => UserFactory::AUTH_HASH];

        $this->actingAs($this->admin, 'sanctum')->postJson($url, $body)->assertForbidden();
        $this->actingAs($this->member, 'sanctum')->postJson($url, $body)->assertForbidden();
        $this->actingAs($this->stranger, 'sanctum')->postJson($url, $body)->assertForbidden();
        expect(ownerCount($this->child))->toBe(1);
    });

    it('requires auth', function () {
        $this->postJson("/api/children/{$this->child->id}/transfer-ownership", ['user_id' => $this->member->id])
            ->assertUnauthorized();
    });

    it('lets the former owner invite and the new owner demote them', function () {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/transfer-ownership", [
                'user_id' => $this->member->id, 'current_auth_hash' => UserFactory::AUTH_HASH,
            ])->assertOk();

        $this->actingAs($this->owner->fresh(), 'sanctum')
            ->postJson("/api/children/{$this->child->id}/invite", ['role' => 'member'])->assertCreated();

        $this->actingAs($this->member->fresh(), 'sanctum')
            ->patchJson("/api/children/{$this->child->id}/members/{$this->owner->id}", ['role' => 'member'])
            ->assertOk();
        expect(roleOf($this->child, $this->owner))->toBe('member');
    });
});

describe('POST /api/children/{child}/leave', function () {
    it('lets a member leave and removes only their rows', function () {
        giveKeys($this->child, $this->member);
        giveKeys($this->child, $this->admin);

        $this->actingAs($this->member, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/leave")->assertOk();

        expect(roleOf($this->child, $this->member))->toBeNull();
        expect(EncryptionKey::where('child_id', $this->child->id)->where('user_id', $this->member->id)->count())->toBe(0);
        expect(EncryptionKey::where('child_id', $this->child->id)->where('user_id', $this->admin->id)->count())->toBe(3);
        expect(roleOf($this->child, $this->admin))->toBe('admin');
        expect(roleOf($this->child, $this->owner))->toBe('owner');
    });

    it('lets an admin leave', function () {
        giveKeys($this->child, $this->admin);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/leave")->assertOk();

        expect(roleOf($this->child, $this->admin))->toBeNull();
        expect(EncryptionKey::where('user_id', $this->admin->id)->count())->toBe(0);
    });

    it('blocks the owner with 409', function () {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/leave")
            ->assertStatus(409)
            ->assertJsonPath('message', 'The owner cannot leave. Transfer ownership to another member first.');

        expect(roleOf($this->child, $this->owner))->toBe('owner');
    });

    it('forbids non-members', function () {
        $this->actingAs($this->stranger, 'sanctum')
            ->postJson("/api/children/{$this->child->id}/leave")->assertForbidden();
    });

    it('requires auth', function () {
        $this->postJson("/api/children/{$this->child->id}/leave")->assertUnauthorized();
    });

    it('revokes the leaver pending invites', function () {
        $invite = Invite::factory()->create([
            'child_id' => $this->child->id, 'invited_by' => $this->admin->id, 'role' => 'admin',
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/children/{$this->child->id}/leave")->assertOk();

        expect($invite->fresh()->revoked_at)->not->toBeNull();
    });

    it('cuts off access to the child and its photos, keeping the photos for others', function () {
        $photo = Photo::factory()->create(['uploaded_by' => $this->member->id]);
        $photo->children()->attach($this->child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);

        $this->actingAs($this->member, 'sanctum')->getJson("/api/photos/{$photo->id}")->assertOk();
        $this->actingAs($this->member, 'sanctum')->postJson("/api/children/{$this->child->id}/leave")->assertOk();

        $this->actingAs($this->member->fresh(), 'sanctum')
            ->getJson("/api/children/{$this->child->id}")->assertForbidden();
        $this->actingAs($this->member->fresh(), 'sanctum')
            ->getJson("/api/photos/{$photo->id}")->assertForbidden();

        expect(Photo::find($photo->id))->not->toBeNull();
        $this->assertDatabaseHas('child_photo', ['photo_id' => $photo->id, 'child_id' => $this->child->id]);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/photos/{$photo->id}")->assertOk();
    });

    it('lets remaining owners delete tags created by a leaver', function () {
        $tag = Tag::factory()->create(['child_id' => $this->child->id, 'created_by' => $this->member->id]);
        $photo = Photo::factory()->create(['uploaded_by' => $this->member->id]);
        $photo->children()->attach($this->child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $photo->tags()->attach($tag->id);

        $this->actingAs($this->member, 'sanctum')->postJson("/api/children/{$this->child->id}/leave")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertNoContent();
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    });
});

describe('DELETE /api/children/{child}/members/{user}', function () {
    it('lets an admin remove a member and drops every key version', function () {
        giveKeys($this->child, $this->member);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/children/{$this->child->id}/members/{$this->member->id}")->assertOk();

        expect(roleOf($this->child, $this->member))->toBeNull();
        expect(EncryptionKey::where('child_id', $this->child->id)->where('user_id', $this->member->id)->count())->toBe(0);
    });

    it('forbids members and non-members', function () {
        $url = "/api/children/{$this->child->id}/members/{$this->admin->id}";

        $this->actingAs($this->member, 'sanctum')->deleteJson($url)->assertForbidden();
        $this->actingAs($this->stranger, 'sanctum')->deleteJson($url)->assertForbidden();
        expect(roleOf($this->child, $this->admin))->toBe('admin');
    });

    it('returns 404 for a cross-family target and leaves it untouched', function () {
        $other = Child::factory()->create();
        $outsider = familyMember('member', $other);
        giveKeys($other, $outsider);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/children/{$this->child->id}/members/{$outsider->id}")->assertNotFound();

        expect(roleOf($other, $outsider))->toBe('member');
        expect(EncryptionKey::where('child_id', $other->id)->count())->toBe(3);
    });

    it('never removes the owner', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/children/{$this->child->id}/members/{$this->owner->id}")->assertForbidden();
        expect(roleOf($this->child, $this->owner))->toBe('owner');
    });

    it('stops an admin removing another admin but lets the owner', function () {
        $second = familyMember('admin', $this->child);
        $url = "/api/children/{$this->child->id}/members/{$second->id}";

        $this->actingAs($this->admin, 'sanctum')->deleteJson($url)->assertForbidden();
        expect(roleOf($this->child, $second))->toBe('admin');

        $this->actingAs($this->owner, 'sanctum')->deleteJson($url)->assertOk();
        expect(roleOf($this->child, $second))->toBeNull();
    });

    it('revokes pending invites of the removed member', function () {
        $invite = Invite::factory()->create([
            'child_id' => $this->child->id, 'invited_by' => $this->member->id,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/children/{$this->child->id}/members/{$this->member->id}")->assertOk();

        expect($invite->fresh()->revoked_at)->not->toBeNull();
    });
});

describe('POST /api/children/{child}/invite role escalation', function () {
    it('lets only the owner mint admin invites', function () {
        $url = "/api/children/{$this->child->id}/invite";

        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['role' => 'admin'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson($url, ['role' => 'member'])->assertCreated();
        $this->actingAs($this->owner, 'sanctum')->postJson($url, ['role' => 'admin'])->assertCreated();
    });
});
