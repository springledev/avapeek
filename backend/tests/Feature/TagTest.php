<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\Tag;
use App\Models\User;

describe('GET /api/children/{child}/tags', function () {
    it('returns tags for a child with photo counts', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $spain = Tag::factory()->create([
            'child_id' => $child->id,
            'name' => 'Spain 2026',
            'created_by' => $user->id,
        ]);
        $school = Tag::factory()->create([
            'child_id' => $child->id,
            'name' => 'First Day of School',
            'created_by' => $user->id,
        ]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $photo->tags()->attach([$spain->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/tags");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'First Day of School')
            ->assertJsonPath('data.0.photo_count', 0)
            ->assertJsonPath('data.1.name', 'Spain 2026')
            ->assertJsonPath('data.1.photo_count', 1);
    });

    it('returns empty list when no tags exist', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/tags");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('prevents non-members from listing tags', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/tags");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->getJson("/api/children/{$child->id}/tags");

        $response->assertStatus(401);
    });
});

describe('POST /api/children/{child}/tags', function () {
    it('creates a new tag for the child', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", [
                'name' => 'Spain 2026',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Spain 2026')
            ->assertJsonPath('data.photo_count', 0);

        $this->assertDatabaseHas('tags', [
            'child_id' => $child->id,
            'name' => 'Spain 2026',
            'created_by' => $user->id,
        ]);
    });

    it('validates name is required', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    it('validates name max length', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", [
                'name' => str_repeat('a', 101),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    it('prevents duplicate tag names for the same child', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        Tag::factory()->create([
            'child_id' => $child->id,
            'name' => 'Spain 2026',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", [
                'name' => 'Spain 2026',
            ]);

        $response->assertStatus(422);
    });

    it('allows same tag name on different children', function () {
        $user = User::factory()->create();
        $child1 = Child::factory()->create(['created_by' => $user->id]);
        $child2 = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child1->id, 'user_id' => $user->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child2->id, 'user_id' => $user->id, 'role' => 'owner']);
        StorageUsage::create(['child_id' => $child1->id, 'used_bytes' => 0]);
        StorageUsage::create(['child_id' => $child2->id, 'used_bytes' => 0]);

        Tag::factory()->create([
            'child_id' => $child1->id,
            'name' => 'Spain 2026',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child2->id}/tags", [
                'name' => 'Spain 2026',
            ]);

        $response->assertStatus(201);
    });

    it('prevents non-members from creating tags', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", [
                'name' => 'Spain 2026',
            ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->postJson("/api/children/{$child->id}/tags", [
            'name' => 'Spain 2026',
        ]);

        $response->assertStatus(401);
    });
});

describe('DELETE /api/children/{child}/tags/{tag}', function () {
    it('allows admin to delete a tag', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $tag = Tag::factory()->create([
            'child_id' => $child->id,
            'name' => 'Old Tag',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/tags/{$tag->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    });

    it('cascades deletion of photo_tags associations', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $tag = Tag::factory()->create([
            'child_id' => $child->id,
            'name' => 'Tag With Photos',
            'created_by' => $user->id,
        ]);
        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $photo->tags()->attach($tag->id);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/tags/{$tag->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag->id]);
    });

    it('does not delete photos when tag is deleted', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $tag = Tag::factory()->create([
            'child_id' => $child->id,
            'name' => 'Safe Tag',
            'created_by' => $user->id,
        ]);
        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $photo->tags()->attach($tag->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/tags/{$tag->id}");

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    });

    it('prevents non-admin members from deleting tags', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member']);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Tag']);

        $response = $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/tags/{$tag->id}");

        $response->assertStatus(403);
    });

    it('returns 404 for tag from different child', function () {
        $user = User::factory()->create();
        $child1 = Child::factory()->create(['created_by' => $user->id]);
        $child2 = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child1->id, 'user_id' => $user->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child2->id, 'user_id' => $user->id, 'role' => 'owner']);
        StorageUsage::create(['child_id' => $child1->id, 'used_bytes' => 0]);
        StorageUsage::create(['child_id' => $child2->id, 'used_bytes' => 0]);

        $tag = Tag::factory()->create(['child_id' => $child2->id, 'name' => 'Other Child Tag']);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child1->id}/tags/{$tag->id}");

        $response->assertStatus(404);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Tag']);

        $response = $this->deleteJson("/api/children/{$child->id}/tags/{$tag->id}");

        $response->assertStatus(401);
    });
});

describe('POST /api/children/{child}/photos/{photo}/tags', function () {
    it('attaches tags to a photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $tag1 = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Spain 2026']);
        $tag2 = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Beach']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/{$photo->id}/tags", [
                'tag_ids' => [$tag1->id, $tag2->id],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.photo_id', $photo->id)
            ->assertJsonCount(2, 'data.tags');

        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag1->id]);
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag2->id]);
    });

    it('does not remove existing tags when attaching new ones', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $existing = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Existing']);
        $photo->tags()->attach($existing->id);

        $newTag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'New']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/{$photo->id}/tags", [
                'tag_ids' => [$newTag->id],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $existing->id]);
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $newTag->id]);
    });

    it('validates tag_ids is required', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/{$photo->id}/tags", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tag_ids']);
    });

    it('validates tag_ids contains existing tags', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/{$photo->id}/tags", [
                'tag_ids' => [99999],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tag_ids.0']);
    });

    it('prevents non-members from attaching tags', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $owner->id]);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Tag']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/{$photo->id}/tags", [
                'tag_ids' => [$tag->id],
            ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $tag = Tag::factory()->create(['child_id' => $photo->child_id, 'name' => 'Tag']);

        $response = $this->postJson("/api/children/{$photo->child_id}/photos/{$photo->id}/tags", [
            'tag_ids' => [$tag->id],
        ]);

        $response->assertStatus(401);
    });
});

describe('DELETE /api/children/{child}/photos/{photo}/tags/{tag}', function () {
    it('detaches a tag from a photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $tag1 = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Keep']);
        $tag2 = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Remove']);
        $photo->tags()->attach([$tag1->id, $tag2->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}/tags/{$tag2->id}");

        $response->assertStatus(204);

        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag1->id]);
        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag2->id]);
    });

    it('does not error when detaching a tag not on the photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Unattached']);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}/tags/{$tag->id}");

        $response->assertStatus(204);
    });

    it('prevents non-members from detaching tags', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $owner->id]);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Tag']);
        $photo->tags()->attach($tag->id);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}/tags/{$tag->id}");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $tag = Tag::factory()->create(['child_id' => $photo->child_id, 'name' => 'Tag']);
        $photo->tags()->attach($tag->id);

        $response = $this->deleteJson("/api/children/{$photo->child_id}/photos/{$photo->id}/tags/{$tag->id}");

        $response->assertStatus(401);
    });
});
