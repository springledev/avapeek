<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Services\StorageService;

function sharedPhotoSetup(string $roleA = 'admin', string $roleB = 'member'): array
{
    $user = User::factory()->create();
    $other = User::factory()->create();
    $childA = Child::factory()->create(['created_by' => $user->id]);
    $childB = Child::factory()->create(['created_by' => $other->id]);
    ChildUser::create(['child_id' => $childA->id, 'user_id' => $user->id, 'role' => $roleA]);
    if ($roleB !== 'none') {
        ChildUser::create(['child_id' => $childB->id, 'user_id' => $user->id, 'role' => $roleB]);
    }
    ChildUser::create(['child_id' => $childB->id, 'user_id' => $other->id, 'role' => 'owner']);

    $photo = Photo::factory()->create(['uploaded_by' => $other->id]);
    createChildPivot($photo, $childA);
    createChildPivot($photo, $childB);

    return [$user, $other, $childA, $childB, $photo];
}

describe('shared photo deletion', function () {
    it('only detaches administrable children and keeps blobs and row', function () {
        [$user, , $childA, $childB, $photo] = sharedPhotoSetup('admin', 'none');

        $this->mock(StorageService::class)->shouldNotReceive('deleteMultiple');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Removed from your children.', 'remaining' => true]);

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('child_photo', ['photo_id' => $photo->id, 'child_id' => $childA->id]);
        $this->assertDatabaseHas('child_photo', ['photo_id' => $photo->id, 'child_id' => $childB->id]);
    });

    it('removes blobs and row when the last link is removed', function () {
        [$user, , , , $photo] = sharedPhotoSetup('admin', 'admin');

        $this->mock(StorageService::class)
            ->shouldReceive('deleteMultiple')->once();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('child_photo', ['photo_id' => $photo->id]);
    });

    it('forbids deletion by a plain member who did not upload', function () {
        [$user, , , , $photo] = sharedPhotoSetup('member', 'member');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    });

    it('uses the highest role across shared children', function () {
        [$user, , , , $photo] = sharedPhotoSetup('member', 'admin');
        expect(app(PhotoPolicy::class)->role($user, $photo))->toBe('admin');

        [$user2, , , , $photo2] = sharedPhotoSetup('admin', 'member');
        expect(app(PhotoPolicy::class)->role($user2, $photo2))->toBe('admin');

        [$user3, , , , $photo3] = sharedPhotoSetup('member', 'owner');
        expect(app(PhotoPolicy::class)->role($user3, $photo3))->toBe('owner');
    });

    it('lets an admin of a later-listed child delete from that child only', function () {
        [$user, , $childA, $childB, $photo] = sharedPhotoSetup('member', 'admin');

        $this->mock(StorageService::class)->shouldNotReceive('deleteMultiple');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Removed from your children.', 'remaining' => true]);

        $this->assertDatabaseMissing('child_photo', ['photo_id' => $photo->id, 'child_id' => $childB->id]);
        $this->assertDatabaseHas('child_photo', ['photo_id' => $photo->id, 'child_id' => $childA->id]);
        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    });
});

describe('shared photo update', function () {
    it('forbids a partial admin from editing a shared photo', function () {
        [$user, , , , $photo] = sharedPhotoSetup('admin', 'none');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01T00:00:00Z'])
            ->assertStatus(403);
    });

    it('allows an admin of every linked child to edit', function () {
        [$user, , , , $photo] = sharedPhotoSetup('admin', 'owner');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01T00:00:00Z'])
            ->assertOk();
    });

    it('allows the uploader to edit', function () {
        $uploader = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $uploader->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $uploader->id, 'role' => 'member']);
        $photo = Photo::factory()->create(['uploaded_by' => $uploader->id]);
        createChildPivot($photo, $child);

        $this->actingAs($uploader, 'sanctum')
            ->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01T00:00:00Z'])
            ->assertOk();
    });
});

describe('policy-backed access checks', function () {
    it('denies thumbnail to non-members', function () {
        $user = User::factory()->create();
        [, , , , $photo] = sharedPhotoSetup();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/thumbnail")
            ->assertStatus(403);
    });

    it('denies comment and tag operations to non-members', function () {
        $user = User::factory()->create();
        [, $other, , , $photo] = sharedPhotoSetup();
        $tag = Tag::factory()->create();
        $comment = Comment::factory()->create(['photo_id' => $photo->id, 'user_id' => $other->id]);

        $this->actingAs($user, 'sanctum');
        $this->getJson("/api/photos/{$photo->id}/comments")->assertStatus(403);
        $this->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")->assertStatus(403);
        $this->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tag->id]])->assertStatus(403);
    });

    it('lets a child admin delete another member comment', function () {
        [$user, $other, , , $photo] = sharedPhotoSetup('member', 'admin');
        $comment = Comment::factory()->create(['photo_id' => $photo->id, 'user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(204);
    });

    it('forbids an admin of one child from deleting a comment by a member of another child only', function () {
        [$user, , , $childB, $photo] = sharedPhotoSetup('admin', 'none');
        $bOnly = User::factory()->create();
        ChildUser::create(['child_id' => $childB->id, 'user_id' => $bOnly->id, 'role' => 'member']);
        $comment = Comment::factory()->create(['photo_id' => $photo->id, 'user_id' => $bOnly->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(403);
    });

    it('lets an admin delete a comment by a member of the same child', function () {
        [$user, , $childA, , $photo] = sharedPhotoSetup('admin', 'none');
        $aMember = User::factory()->create();
        ChildUser::create(['child_id' => $childA->id, 'user_id' => $aMember->id, 'role' => 'member']);
        $comment = Comment::factory()->create(['photo_id' => $photo->id, 'user_id' => $aMember->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(204);
    });

    it('forbids an uploader who is not a member of every linked child from editing', function () {
        $uploader = User::factory()->create();
        $other = User::factory()->create();
        $childA = Child::factory()->create(['created_by' => $uploader->id]);
        $childB = Child::factory()->create(['created_by' => $other->id]);
        ChildUser::create(['child_id' => $childA->id, 'user_id' => $uploader->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $childB->id, 'user_id' => $other->id, 'role' => 'owner']);
        $photo = Photo::factory()->create(['uploaded_by' => $uploader->id]);
        createChildPivot($photo, $childA);
        createChildPivot($photo, $childB);

        $this->actingAs($uploader, 'sanctum')
            ->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2020-01-01T00:00:00Z'])
            ->assertStatus(403);
    });
});
