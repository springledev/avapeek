<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\EncryptionKey;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

function childWithRole(string $role, ?Child $child = null): array
{
    $user = User::factory()->create();
    $child ??= Child::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

    return [$user, $child];
}

function linkPhoto(Photo $photo, Child $child): void
{
    $photo->children()->attach($child->id, [
        'encrypted_pek' => bin2hex(random_bytes(32)),
        'iv_pek' => bin2hex(random_bytes(16)),
    ]);
}

describe('PUT /api/children/{child}', function () {
    it('lets the owner update name and birth date and keeps the slug', function () {
        [$owner, $child] = childWithRole('owner');
        $slug = $child->slug;

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => 'Olivia', 'birth_date' => '2023-01-02'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Olivia')
            ->assertJsonPath('data.birth_date', '2023-01-02')
            ->assertJsonPath('data.slug', $slug)
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonStructure(['data' => ['id', 'age', 'member_count', 'photo_count', 'avatar_color']]);

        $this->assertDatabaseHas('children', ['id' => $child->id, 'name' => 'Olivia', 'slug' => $slug]);
    });

    it('lets an admin update and can clear the birth date', function () {
        [$admin, $child] = childWithRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => 'Noah', 'birth_date' => null])
            ->assertOk()
            ->assertJsonPath('data.birth_date', null)
            ->assertJsonPath('data.role', 'admin');
    });

    it('rejects a plain member', function () {
        [$member, $child] = childWithRole('member');

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        expect($child->fresh()->name)->not->toBe('Hacked');
    });

    it('blocks non-members and other families', function () {
        [, $child] = childWithRole('owner');
        $stranger = User::factory()->create();
        childWithRole('owner'); // unrelated family

        $this->actingAs($stranger, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        expect($child->fresh()->name)->not->toBe('Hacked');
    });

    it('validates name and birth date', function () {
        [$owner, $child] = childWithRole('owner');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => '<script>'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/children/{$child->id}", ['name' => 'Ok', 'birth_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('birth_date');
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $this->putJson("/api/children/{$child->id}", ['name' => 'X'])->assertUnauthorized();
    });
});

describe('DELETE /api/children/{child}', function () {
    it('rejects admins and members', function () {
        [$admin, $child] = childWithRole('admin');
        [$member] = childWithRole('member', $child);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/children/{$child->id}")->assertForbidden();
        $this->actingAs($member, 'sanctum')->deleteJson("/api/children/{$child->id}")->assertForbidden();

        $this->assertDatabaseHas('children', ['id' => $child->id]);
    });

    it('blocks non-members', function () {
        [, $child] = childWithRole('owner');
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/children/{$child->id}")->assertForbidden();

        $this->assertDatabaseHas('children', ['id' => $child->id]);
    });

    it('removes the child, keys, members and invites', function () {
        [$owner, $child] = childWithRole('owner');
        [$member] = childWithRole('member', $child);
        EncryptionKey::create(['child_id' => $child->id, 'user_id' => $owner->id, 'encrypted_dek' => 'x', 'key_version' => 1]);
        EncryptionKey::create(['child_id' => $child->id, 'user_id' => $member->id, 'encrypted_dek' => 'y', 'key_version' => 1]);
        Invite::factory()->create(['child_id' => $child->id, 'invited_by' => $owner->id]);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/children/{$child->id}")->assertNoContent();

        $this->assertDatabaseMissing('children', ['id' => $child->id]);
        $this->assertDatabaseMissing('encryption_keys', ['child_id' => $child->id]);
        $this->assertDatabaseMissing('child_users', ['child_id' => $child->id]);
        $this->assertDatabaseMissing('invites', ['child_id' => $child->id]);
    });

    it('keeps shared photos for the other child and deletes exclusive photos with their blobs', function () {
        [$owner, $child] = childWithRole('owner');
        $other = Child::factory()->create();
        ChildUser::create(['child_id' => $other->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $shared = Photo::factory()->create(['uploaded_by' => $owner->id, 'stored_bytes' => 1000]);
        $exclusive = Photo::factory()->create(['uploaded_by' => $owner->id, 'stored_bytes' => 2500]);
        linkPhoto($shared, $child);
        linkPhoto($shared, $other);
        linkPhoto($exclusive, $child);

        foreach ([$shared, $exclusive] as $photo) {
            Storage::disk('s3')->put($photo->original_path, 'a');
            Storage::disk('s3')->put($photo->thumbnail_path, 'b');
        }
        StorageUsage::global()->update(['used_bytes' => 3500]);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/children/{$child->id}")->assertNoContent();

        $this->assertDatabaseHas('photos', ['id' => $shared->id]);
        $this->assertDatabaseHas('child_photo', ['photo_id' => $shared->id, 'child_id' => $other->id]);
        $this->assertDatabaseMissing('child_photo', ['photo_id' => $shared->id, 'child_id' => $child->id]);
        Storage::disk('s3')->assertExists([$shared->original_path, $shared->thumbnail_path]);

        $this->assertDatabaseMissing('photos', ['id' => $exclusive->id]);
        Storage::disk('s3')->assertMissing([$exclusive->original_path, $exclusive->thumbnail_path]);

        expect((int) StorageUsage::global()->fresh()->used_bytes)->toBe(1000);
    });
});
