<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * A child with an owner, plus a plain member who uploaded a photo.
 *
 * @return array{Child, User, User, Photo}
 */
function viewOnlyFixture(bool $memberCanUpload): array
{
    $owner = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $owner->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);

    $member = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member', 'can_upload' => $memberCanUpload]);

    $photo = Photo::factory()->create(['uploaded_by' => $member->id]);
    createChildPivot($photo, $child);

    return [$child, $owner, $member, $photo];
}

describe('member whose upload permission was revoked', function () {
    it('can no longer delete or edit their own earlier upload', function () {
        [, , $member, $photo] = viewOnlyFixture(false);

        $this->actingAs($member, 'sanctum')->deleteJson("/api/photos/{$photo->id}")->assertForbidden();
        $this->actingAs($member, 'sanctum')->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01'])->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson('/api/photos/bulk-delete', ['photo_ids' => [$photo->id]])
            ->assertOk()
            ->assertExactJson(['deleted' => [], 'detached' => [], 'forbidden' => [$photo->id]]);

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('child_photo', ['photo_id' => $photo->id]);
    });

    it('cannot tag, share or bulk tag, but can still view', function () {
        [$child, , $member, $photo] = viewOnlyFixture(false);
        $tag = Tag::factory()->create(['child_id' => $child->id]);
        $otherChild = Child::factory()->create();
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $member->id, 'role' => 'member', 'can_upload' => false]);

        $this->actingAs($member, 'sanctum')->getJson("/api/photos/{$photo->id}")->assertOk();
        $this->actingAs($member, 'sanctum')->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tag->id]])->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson('/api/photos/bulk-tags', [
            'assignments' => [['photo_id' => $photo->id, 'tag_ids' => [$tag->id]]],
        ])->assertOk()->assertJson(['updated' => [], 'skipped' => [$photo->id]]);
        $this->actingAs($member, 'sanctum')->postJson('/api/photos/share', [
            'child_id' => $otherChild->id,
            'photos' => [['photo_id' => $photo->id, 'encrypted_pek' => base64_encode(random_bytes(48)), 'iv_pek' => base64_encode(random_bytes(12))]],
        ])->assertOk()->assertJson(['shared' => []]);

        $this->assertDatabaseMissing('child_photo', ['photo_id' => $photo->id, 'child_id' => $otherChild->id]);
    });

    it('reports no delete or edit ability in the feed, recents and photo detail', function () {
        [, , $member, $photo] = viewOnlyFixture(false);

        foreach (['/api/photos', '/api/recents'] as $url) {
            $this->actingAs($member, 'sanctum')->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.0.can_delete', false)
                ->assertJsonPath('data.0.can_edit', false);
        }

        $this->actingAs($member, 'sanctum')->getJson("/api/photos/{$photo->id}")
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.can_edit', false);
    });
});

describe('member with upload permission', function () {
    it('can delete and edit their own upload and sees the abilities', function () {
        [, , $member, $photo] = viewOnlyFixture(true);

        $this->actingAs($member, 'sanctum')->getJson('/api/photos')
            ->assertJsonPath('data.0.can_delete', true)
            ->assertJsonPath('data.0.can_edit', true);

        $this->actingAs($member, 'sanctum')->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01'])
            ->assertOk()
            ->assertJsonPath('data.can_edit', true);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/photos/{$photo->id}")->assertStatus(204);
    });

    it('cannot delete a photo someone else uploaded', function () {
        [, $owner, $member, $photo] = viewOnlyFixture(true);
        $photo->update(['uploaded_by' => $owner->id]);

        $this->actingAs($member, 'sanctum')->deleteJson("/api/photos/{$photo->id}")->assertForbidden();
        $this->actingAs($member, 'sanctum')->getJson('/api/photos')
            ->assertJsonPath('data.0.can_delete', false);
    });
});

it('lets the owner delete and edit any photo in their child', function () {
    [, $owner, , $photo] = viewOnlyFixture(false);

    $this->actingAs($owner, 'sanctum')->getJson('/api/photos')
        ->assertJsonPath('data.0.can_delete', true)
        ->assertJsonPath('data.0.can_edit', true);
    $this->actingAs($owner, 'sanctum')->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01'])->assertOk();
});

it('does not run extra queries per photo for abilities', function () {
    [$child, , $member] = viewOnlyFixture(true);
    Photo::factory()->count(5)->create(['uploaded_by' => $member->id])
        ->each(fn (Photo $p) => createChildPivot($p, $child));

    $this->actingAs($member, 'sanctum');
    DB::enableQueryLog();
    $this->getJson('/api/photos')->assertOk();
    $few = count(DB::getQueryLog());

    Photo::factory()->count(10)->create(['uploaded_by' => $member->id])
        ->each(fn (Photo $p) => createChildPivot($p, $child));
    DB::flushQueryLog();
    $this->getJson('/api/photos')->assertOk();

    expect(count(DB::getQueryLog()))->toBe($few);
});
