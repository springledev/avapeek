<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;

describe('GET /api/tags', function () {
    it('lists global tags visible to the user with photo counts', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $spain = Tag::factory()->create(['name' => 'Spain 2026']);
        $school = Tag::factory()->create(['name' => 'First Day of School']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 'test', 'iv_pek' => 'test_test_test_test!']);
        $photo->tags()->attach([$spain->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/tags');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'First Day of School')
            ->assertJsonPath('data.0.photo_count', 0)
            ->assertJsonPath('data.1.name', 'Spain 2026')
            ->assertJsonPath('data.1.photo_count', 1);
    });

    it('returns empty list when no tags exist', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/tags');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('requires authentication', function () {
        $this->getJson('/api/tags')->assertStatus(401);
    });

    it('only shows tags from photos the user has access to', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $child1 = Child::factory()->create(['created_by' => $user1->id]);
        $child2 = Child::factory()->create(['created_by' => $user2->id]);
        ChildUser::create(['child_id' => $child1->id, 'user_id' => $user1->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child2->id, 'user_id' => $user2->id, 'role' => 'owner']);

        $tag = Tag::factory()->create(['name' => 'Private Tag']);
        $photo1 = Photo::factory()->create(['uploaded_by' => $user1->id]);
        $photo1->children()->attach($child1->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $photo1->tags()->attach($tag->id);

        $photo2 = Photo::factory()->create(['uploaded_by' => $user2->id]);
        $photo2->children()->attach($child2->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $photo2->tags()->attach($tag->id);

        $response = $this->actingAs($user1, 'sanctum')
            ->getJson('/api/tags');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Private Tag')
            ->assertJsonPath('data.0.photo_count', 1);
    });
});

describe('POST /api/tags', function () {
    it('creates a global tag', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tags', ['name' => 'Spain 2026']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Spain 2026')
            ->assertJsonPath('data.photo_count', 0);

        $this->assertDatabaseHas('tags', ['name' => 'Spain 2026']);
    });

    it('requires authentication', function () {
        $this->postJson('/api/tags', ['name' => 'Spain 2026'])->assertStatus(401);
    });

    it('validates name is required', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tags', [])
            ->assertStatus(422);
    });

    it('prevents duplicate tag names globally', function () {
        $user = User::factory()->create();
        Tag::factory()->create(['name' => 'Spain 2026']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tags', ['name' => 'Spain 2026'])
            ->assertStatus(422);
    });
});

describe('DELETE /api/tags/{tag}', function () {
    it('deletes a tag and removes all photo associations', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $tag = Tag::factory()->create();
        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $photo->tags()->attach($tag->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/tags/{$tag->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('photo_tags', ['tag_id' => $tag->id]);
    });

    it('requires authentication', function () {
        $tag = Tag::factory()->create();
        $this->deleteJson("/api/tags/{$tag->id}")->assertStatus(401);
    });

    it('returns 404 for non-existent tags', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/tags/99999')
            ->assertStatus(404);
    });
});

describe('POST /api/photos/{photo}/tags (attach)', function () {
    it('attaches tags to a photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);

        $tag = Tag::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tag->id]]);

        $response->assertStatus(200)
            ->assertJsonPath('data.photo_id', $photo->id)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tag->id);

        $this->assertDatabaseHas('photo_tags', [
            'photo_id' => $photo->id,
            'tag_id' => $tag->id,
        ]);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $this->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [1]])->assertStatus(401);
    });
});

describe('DELETE /api/photos/{photo}/tags/{tag} (detach)', function () {
    it('detaches a tag from a photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);

        $tag = Tag::factory()->create();
        $photo->tags()->attach($tag->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('photo_tags', [
            'photo_id' => $photo->id,
            'tag_id' => $tag->id,
        ]);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $tag = Tag::factory()->create();
        $this->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")->assertStatus(401);
    });
});
