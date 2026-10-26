<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function permissionFamily(): array
{
    $owner = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $owner->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);

    $admin = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $admin->id, 'role' => 'admin']);

    $uploader = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $uploader->id, 'role' => 'member', 'can_upload' => true]);

    $viewer = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $viewer->id, 'role' => 'member', 'can_upload' => false]);

    return [$child, $owner, $admin, $uploader, $viewer];
}

function permissionUploadBody(Child $child): array
{
    return [
        'file' => UploadedFile::fake()->create('photo.jpg', 100),
        'iv' => bin2hex(random_bytes(16)),
        'thumbnail' => UploadedFile::fake()->create('thumb.webp', 50),
        'iv_thumbnail' => bin2hex(random_bytes(16)),
        'original_mime' => 'image/jpeg',
        'size_bytes' => 100,
        'children' => json_encode([$child->id => [
            'encrypted_pek' => bin2hex(random_bytes(32)),
            'iv_pek' => bin2hex(random_bytes(16)),
        ]]),
    ];
}

beforeEach(function () {
    Storage::fake('s3');
});

describe('uploading', function () {
    it('lets owners, admins and uploaders upload but not viewers', function () {
        [$child, $owner, $admin, $uploader, $viewer] = permissionFamily();

        foreach ([$owner, $admin, $uploader] as $user) {
            $this->actingAs($user, 'sanctum')->post('/api/photos/upload', permissionUploadBody($child))->assertCreated();
        }

        $this->actingAs($viewer, 'sanctum')->post('/api/photos/upload', permissionUploadBody($child))->assertForbidden();
        expect(Photo::count())->toBe(3);
    });

    it('refuses an upload that includes any child the member cannot upload to', function () {
        [$child, , , $uploader] = permissionFamily();
        $other = Child::factory()->create();
        ChildUser::create(['child_id' => $other->id, 'user_id' => $uploader->id, 'role' => 'member', 'can_upload' => false]);

        $body = permissionUploadBody($child);
        $body['children'] = json_encode([
            $child->id => ['encrypted_pek' => bin2hex(random_bytes(32)), 'iv_pek' => bin2hex(random_bytes(16))],
            $other->id => ['encrypted_pek' => bin2hex(random_bytes(32)), 'iv_pek' => bin2hex(random_bytes(16))],
        ]);

        $this->actingAs($uploader, 'sanctum')->post('/api/photos/upload', $body)->assertForbidden();
        expect(Photo::count())->toBe(0);
    });

    it('refuses an upload intent when the user cannot upload anywhere', function () {
        config(['avapeek.uploads.direct' => true]);
        [, , , , $viewer] = permissionFamily();

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/photos/upload-intent', ['original_bytes' => 1000, 'thumbnail_bytes' => 100, 'original_mime' => 'image/jpeg'])
            ->assertForbidden();
    });
});

describe('tags', function () {
    it('lets owners, admins and uploaders create tags but not viewers', function () {
        [$child, $owner, $admin, $uploader, $viewer] = permissionFamily();
        $body = fn () => [
            'name_encrypted' => base64_encode(random_bytes(24)),
            'iv_name' => bin2hex(random_bytes(16)),
        ];

        foreach ([$owner, $admin, $uploader] as $user) {
            $this->actingAs($user, 'sanctum')->postJson("/api/children/{$child->id}/tags", $body())->assertCreated();
        }

        $this->actingAs($viewer, 'sanctum')->postJson("/api/children/{$child->id}/tags", $body())->assertForbidden();
        expect(Tag::count())->toBe(3);
    });

    it('lets a viewer list tags', function () {
        [$child, , , , $viewer] = permissionFamily();
        Tag::factory()->create(['child_id' => $child->id]);

        $this->actingAs($viewer, 'sanctum')->getJson("/api/children/{$child->id}/tags")->assertOk()->assertJsonCount(1, 'data');
    });

    it('lets an uploader but not a viewer attach and detach tags', function () {
        [$child, $owner, , $uploader, $viewer] = permissionFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $owner->id]);
        $photo = Photo::factory()->create(['uploaded_by' => $owner->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);

        $this->actingAs($viewer, 'sanctum')->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tag->id]])->assertForbidden();
        $this->actingAs($uploader, 'sanctum')->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tag->id]])->assertOk();

        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")->assertForbidden();
        expect($photo->tags()->count())->toBe(1);

        $this->actingAs($uploader, 'sanctum')->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")->assertNoContent();
        expect($photo->tags()->count())->toBe(0);
    });

    it('skips bulk tagging for a viewer', function () {
        [$child, $owner, , , $viewer] = permissionFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $owner->id]);
        $photo = Photo::factory()->create(['uploaded_by' => $owner->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/photos/bulk-tags', ['assignments' => [['photo_id' => $photo->id, 'tag_ids' => [$tag->id]]]])
            ->assertOk()
            ->assertJsonPath('updated', [])
            ->assertJsonPath('skipped', [$photo->id]);
    });

    it('lets an uploader but not a viewer delete a tag', function () {
        [$child, $owner, , $uploader, $viewer] = permissionFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $owner->id]);

        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertForbidden();
        $this->actingAs($uploader, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertNoContent();
    });
});

describe('sharing photos into a child', function () {
    it('needs upload permission in the target child', function () {
        [$child, $owner, , $uploader, $viewer] = permissionFamily();
        $source = Child::factory()->create();
        $photo = Photo::factory()->create(['uploaded_by' => $owner->id]);
        $photo->children()->attach($source->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        foreach ([$uploader, $viewer] as $user) {
            ChildUser::create(['child_id' => $source->id, 'user_id' => $user->id, 'role' => 'member']);
        }

        $payload = ['child_id' => $child->id, 'photos' => [[
            'photo_id' => $photo->id,
            'encrypted_pek' => base64_encode(random_bytes(48)),
            'iv_pek' => base64_encode(random_bytes(12)),
        ]]];

        $this->actingAs($viewer, 'sanctum')->postJson('/api/photos/share', $payload)->assertOk()->assertJsonPath('shared', []);
        $this->actingAs($uploader, 'sanctum')->postJson('/api/photos/share', $payload)->assertOk()->assertJsonPath('shared', [$photo->id]);
    });
});

describe('PATCH /api/children/{child}/members/{user}/permissions', function () {
    it('lets an owner or admin change a member upload permission', function () {
        [$child, $owner, $admin, $uploader, $viewer] = permissionFamily();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/{$uploader->id}/permissions", ['can_upload' => false])
            ->assertOk()
            ->assertJsonPath('data.can_upload', false);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/{$viewer->id}/permissions", ['can_upload' => true])
            ->assertOk()
            ->assertJsonPath('data.can_upload', true);

        $this->actingAs($uploader, 'sanctum')->post('/api/photos/upload', permissionUploadBody($child))->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->post('/api/photos/upload', permissionUploadBody($child))->assertCreated();
    });

    it('does not let a member change permissions', function () {
        [$child, , , $uploader, $viewer] = permissionFamily();

        $this->actingAs($uploader, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/{$viewer->id}/permissions", ['can_upload' => true])
            ->assertForbidden();
    });

    it('does not let an outsider change permissions', function () {
        [$child, , , , $viewer] = permissionFamily();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/{$viewer->id}/permissions", ['can_upload' => true])
            ->assertForbidden();
    });

    it('refuses to edit an owner or admin, and unknown members', function () {
        [$child, $owner, $admin] = permissionFamily();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/{$admin->id}/permissions", ['can_upload' => false])
            ->assertStatus(422);
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/999999/permissions", ['can_upload' => false])
            ->assertNotFound();
    });

    it('validates the flag', function () {
        [$child, $owner, , , $viewer] = permissionFamily();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/children/{$child->id}/members/{$viewer->id}/permissions", [])
            ->assertStatus(422);
    });
});

describe('invites and payloads', function () {
    it('stores the upload permission chosen at invite time and gives it to the new member', function () {
        [$child, $owner] = permissionFamily();

        $token = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", ['role' => 'member', 'can_upload' => false])
            ->assertCreated()
            ->assertJsonPath('data.can_upload', false)
            ->json('data.token');

        $invitee = User::factory()->create();
        $this->actingAs($invitee, 'sanctum')->postJson("/api/invites/{$token}/accept")->assertOk();

        $this->assertDatabaseHas('child_users', ['child_id' => $child->id, 'user_id' => $invitee->id, 'can_upload' => false]);
    });

    it('defaults invites to upload permission', function () {
        [$child, $owner] = permissionFamily();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/children/{$child->id}/invite", ['role' => 'member'])
            ->assertCreated()
            ->assertJsonPath('data.can_upload', true);
    });

    it('applies the invite permission when registering through an invite', function () {
        [$child] = permissionFamily();
        $invite = Invite::factory()->create(['child_id' => $child->id, 'role' => 'member', 'can_upload' => false]);

        $this->postJson('/api/auth/register', [
            'email' => 'new@example.com',
            'auth_hash' => str_repeat('a', 64),
            'display_name' => 'New',
            'master_key_salt' => str_repeat('a', 64),
            'public_key' => '{}',
            'encrypted_private_key' => 'abc',
            'invite_token' => $invite->token,
        ])->assertCreated();

        $this->assertDatabaseHas('child_users', ['child_id' => $child->id, 'user_id' => User::where('email', 'new@example.com')->value('id'), 'can_upload' => false]);
    });

    it('reports can_upload in the child list, detail and member list', function () {
        [$child, $owner, , $uploader, $viewer] = permissionFamily();

        $this->actingAs($viewer, 'sanctum')->getJson('/api/children')->assertJsonPath('data.0.can_upload', false);
        $this->actingAs($uploader, 'sanctum')->getJson('/api/children')->assertJsonPath('data.0.can_upload', true);
        $this->actingAs($owner, 'sanctum')->getJson('/api/children')->assertJsonPath('data.0.can_upload', true);

        $detail = $this->actingAs($owner, 'sanctum')->getJson("/api/children/{$child->id}")->assertOk();
        $byId = collect($detail->json('data.members'))->keyBy('id');
        expect($byId[$viewer->id]['can_upload'])->toBeFalse()
            ->and($byId[$uploader->id]['can_upload'])->toBeTrue()
            ->and($byId[$owner->id]['can_upload'])->toBeTrue();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/children/{$child->id}")->assertJsonPath('data.can_upload', false);
    });
});
