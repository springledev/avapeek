<?php

use App\Models\Album;
use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\User;

describe('GET /api/children/{child}/albums', function () {
    it('returns paginated albums for a child with photo counts', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $album1 = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);
        $album2 = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        Photo::factory()->count(3)->create([
            'album_id' => $album1->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
        ]);
        Photo::factory()->count(1)->create([
            'album_id' => $album2->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/albums");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $albums = $response->json('data');
        expect(collect($albums)->firstWhere('id', $album1->id)['photo_count'])->toBe(3);
        expect(collect($albums)->firstWhere('id', $album2->id)['photo_count'])->toBe(1);
    });

    it('returns cover thumbnail from most recent photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        $olderPhoto = Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_sm_path' => 'older-thumb.webp.enc',
            'iv_sm' => 'older-iv',
            'taken_at' => now()->subDays(10),
        ]);
        $newerPhoto = Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_sm_path' => 'newer-thumb.webp.enc',
            'iv_sm' => 'newer-iv',
            'taken_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/albums");

        $albumData = collect($response->json('data'))->firstWhere('id', $album->id);
        expect($albumData['cover_thumbnail_path'])->toBe('newer-thumb.webp.enc');
        expect($albumData['cover_iv'])->toBe('newer-iv');
    });

    it('returns null cover when album has no photos', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/albums");

        $albumData = collect($response->json('data'))->firstWhere('id', $album->id);
        expect($albumData['cover_thumbnail_path'])->toBeNull();
        expect($albumData['cover_iv'])->toBeNull();
        expect($albumData['photo_count'])->toBe(0);
    });

    it('prevents non-members from listing albums', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/albums");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->getJson("/api/children/{$child->id}/albums");

        $response->assertStatus(401);
    });

    it('returns albums in descending order by created_at', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $album1 = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
            'created_at' => now()->subDays(5),
        ]);
        $album2 = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
            'created_at' => now()->subDays(1),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/albums");

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        expect($ids[0])->toBe($album2->id);
        expect($ids[1])->toBe($album1->id);
    });
});

describe('POST /api/children/{child}/albums', function () {
    it('creates an album within a child profile', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/albums", [
                'title' => 'Summer Vacation',
                'description' => 'Photos from our beach trip',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Summer Vacation')
            ->assertJsonPath('data.description', 'Photos from our beach trip')
            ->assertJsonPath('data.photo_count', 0)
            ->assertJsonPath('data.cover_thumbnail_path', null)
            ->assertJsonPath('data.created_by', $user->id);

        $this->assertDatabaseHas('albums', [
            'child_id' => $child->id,
            'title' => 'Summer Vacation',
            'created_by' => $user->id,
        ]);
    });

    it('allows creating an album without a description', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/albums", [
                'title' => 'First Steps',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.description', null);
    });

    it('allows any member to create an album', function () {
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
            ->postJson("/api/children/{$child->id}/albums", [
                'title' => 'Member Album',
            ]);

        $response->assertStatus(201);
    });

    it('prevents non-members from creating albums', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/albums", [
                'title' => 'Unauthorized Album',
            ]);

        $response->assertStatus(403);
    });

    it('validates required title', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/albums", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    });

    it('validates title max length', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/albums", [
                'title' => str_repeat('a', 201),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->postJson("/api/children/{$child->id}/albums", [
            'title' => 'Test Album',
        ]);

        $response->assertStatus(401);
    });
});

describe('GET /api/albums/{album}', function () {
    it('returns album details with photo count and cover', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        $photo = Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_sm_path' => 'cover-thumb.webp.enc',
            'iv_sm' => 'cover-iv-hex',
            'taken_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/albums/{$album->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $album->id)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonPath('data.title', $album->title)
            ->assertJsonPath('data.photo_count', 1)
            ->assertJsonPath('data.cover_thumbnail_path', 'cover-thumb.webp.enc')
            ->assertJsonPath('data.cover_iv', 'cover-iv-hex')
            ->assertJsonPath('data.created_by', $user->id);
    });

    it('prevents non-members from viewing an album', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/albums/{$album->id}");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $album = Album::factory()->create();

        $response = $this->getJson("/api/albums/{$album->id}");

        $response->assertStatus(401);
    });

    it('returns 404 for nonexistent album', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/albums/99999');

        $response->assertStatus(404);
    });
});

describe('PUT /api/albums/{album}', function () {
    it('allows owner to update album title and description', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
            'title' => 'Old Title',
            'description' => 'Old description',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/albums/{$album->id}", [
                'title' => 'Updated Title',
                'description' => 'Updated description',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.description', 'Updated description');

        $this->assertDatabaseHas('albums', [
            'id' => $album->id,
            'title' => 'Updated Title',
            'description' => 'Updated description',
        ]);
    });

    it('allows admin to update album', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $admin->id,
            'role' => 'admin',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
            'title' => 'Original',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/albums/{$album->id}", [
                'title' => 'Admin Updated',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Admin Updated');
    });

    it('prevents regular member from updating album', function () {
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
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($member, 'sanctum')
            ->putJson("/api/albums/{$album->id}", [
                'title' => 'Should Not Update',
            ]);

        $response->assertStatus(403);
    });

    it('prevents non-members from updating album', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/albums/{$album->id}", [
                'title' => 'Should Not Update',
            ]);

        $response->assertStatus(403);
    });

    it('allows partial updates (title only)', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
            'title' => 'Old',
            'description' => 'Keep this',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/albums/{$album->id}", [
                'title' => 'New Title',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'New Title')
            ->assertJsonPath('data.description', 'Keep this');
    });

    it('validates title max length on update', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/albums/{$album->id}", [
                'title' => str_repeat('a', 201),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    });

    it('requires authentication', function () {
        $album = Album::factory()->create();

        $response = $this->putJson("/api/albums/{$album->id}", [
            'title' => 'Test',
        ]);

        $response->assertStatus(401);
    });
});

describe('DELETE /api/albums/{album}', function () {
    it('allows owner to delete an album with no photos', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/albums/{$album->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.photos_deleted', 0)
            ->assertJsonPath('data.bytes_reclaimed', 0);

        $this->assertDatabaseMissing('albums', ['id' => $album->id]);
    });

    it('allows admin to delete an album', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $admin->id,
            'role' => 'admin',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/albums/{$album->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('albums', ['id' => $album->id]);
    });

    it('prevents regular member from deleting album', function () {
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
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/albums/{$album->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('albums', ['id' => $album->id]);
    });

    it('prevents non-members from deleting album', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/albums/{$album->id}");

        $response->assertStatus(403);
    });

    it('deletes associated photos when deleting an album', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        $photo1 = Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'size_bytes' => 100000,
        ]);
        $photo2 = Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'size_bytes' => 200000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/albums/{$album->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.photos_deleted', 2)
            ->assertJsonPath('data.bytes_reclaimed', 300000);

        $this->assertDatabaseMissing('photos', ['id' => $photo1->id]);
        $this->assertDatabaseMissing('photos', ['id' => $photo2->id]);
    });

    it('decrements storage usage when deleting album with photos', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 500000]);

        $album = Album::factory()->create([
            'child_id' => $child->id,
            'created_by' => $user->id,
        ]);

        Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'size_bytes' => 150000,
        ]);
        Photo::factory()->create([
            'album_id' => $album->id,
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'size_bytes' => 100000,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/albums/{$album->id}");

        $this->assertDatabaseHas('storage_usage', [
            'child_id' => $child->id,
            'used_bytes' => 250000,
        ]);
    });

    it('requires authentication', function () {
        $album = Album::factory()->create();

        $response = $this->deleteJson("/api/albums/{$album->id}");

        $response->assertStatus(401);
    });
});
