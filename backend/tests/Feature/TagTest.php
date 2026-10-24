<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;

describe('GET /api/tags', function () {
    it('lists global tags visible to the user with photo counts', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $spain = Tag::factory()->create(['name' => 'Spain 2026', 'created_by' => $user->id]);
        $school = Tag::factory()->create(['name' => 'First Day of School', 'created_by' => $user->id]);

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
        Tag::factory()->create(['name' => 'Spain 2026', 'created_by' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tags', ['name' => 'Spain 2026'])
            ->assertStatus(422);
    });

    it('allows the same name in an unrelated family', function () {
        $user = User::factory()->create();
        Tag::factory()->create(['name' => 'Spain 2026']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tags', ['name' => 'Spain 2026'])
            ->assertStatus(201);
    });
});

describe('DELETE /api/tags/{tag}', function () {
    it('deletes a tag and removes all photo associations', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $tag = Tag::factory()->create(['created_by' => $user->id]);
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

        $tag = Tag::factory()->create(['created_by' => $user->id]);

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

describe('tag family scoping', function () {
    function familyUser(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $child];
    }

    it('hides tags of unrelated families from the index', function () {
        [$alice] = familyUser();
        [$bob] = familyUser();
        Tag::factory()->create(['name' => 'Bobs Secret', 'created_by' => $bob->id]);

        $this->actingAs($alice, 'sanctum')->getJson('/api/tags')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('shows tags created by co-members', function () {
        [$alice, $child] = familyUser();
        $partner = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $partner->id, 'role' => 'member']);
        Tag::factory()->create(['name' => 'Shared', 'created_by' => $partner->id]);

        $this->actingAs($alice, 'sanctum')->getJson('/api/tags')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Shared');
    });

    it('does not let an outsider delete a tag', function () {
        [$alice] = familyUser();
        [$bob] = familyUser();
        $tag = Tag::factory()->create(['created_by' => $alice->id]);

        $this->actingAs($bob, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(404);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    });

    it('does not let a plain co-member delete another member tag', function () {
        [$alice, $child] = familyUser();
        $member = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member']);
        $tag = Tag::factory()->create(['created_by' => $alice->id]);

        $this->actingAs($member, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(403);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    });

    it('lets the creator and a family admin delete a tag', function () {
        [$alice, $child] = familyUser();
        $member = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member']);
        $memberTag = Tag::factory()->create(['created_by' => $member->id]);
        $ownTag = Tag::factory()->create(['created_by' => $member->id]);

        $this->actingAs($alice, 'sanctum')->deleteJson("/api/tags/{$memberTag->id}")->assertStatus(204);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/tags/{$ownTag->id}")->assertStatus(204);
    });

    it('rejects attaching a tag from another family', function () {
        [$alice, $child] = familyUser();
        [$bob] = familyUser();
        $photo = Photo::factory()->create(['uploaded_by' => $alice->id]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $foreign = Tag::factory()->create(['created_by' => $bob->id]);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$foreign->id]])
            ->assertStatus(422);
        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $foreign->id]);
    });
});

describe('tag scoping on upload and detach', function () {
    it('creates a new tag on upload instead of reusing an unrelated family tag', function () {
        $alice = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $alice->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $alice->id, 'role' => 'owner']);
        $bob = User::factory()->create();
        $foreign = Tag::factory()->create(['name' => 'Spain 2026', 'created_by' => $bob->id]);

        $response = $this->actingAs($alice, 'sanctum')
            ->post('/api/photos/upload', [
                'file' => UploadedFile::fake()->create('photo.heic', 100),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 50),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/heic',
                'size_bytes' => 100,
                'children' => json_encode([$child->id => [
                    'encrypted_pek' => bin2hex(random_bytes(32)),
                    'iv_pek' => bin2hex(random_bytes(16)),
                ]]),
                'tags' => ['Spain 2026'],
            ]);

        $response->assertStatus(201);
        $photoId = $response->json('data.id');
        $own = Tag::where('name', 'Spain 2026')->where('created_by', $alice->id)->first();
        expect($own)->not->toBeNull()->and($own->id)->not->toBe($foreign->id);
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photoId, 'tag_id' => $own->id]);
        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photoId, 'tag_id' => $foreign->id]);
    });

    it('returns 204 on detaching a non-visible tag and leaves other photos untouched', function () {
        $alice = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $alice->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $alice->id, 'role' => 'owner']);
        $bob = User::factory()->create();
        $bobChild = Child::factory()->create(['created_by' => $bob->id]);
        ChildUser::create(['child_id' => $bobChild->id, 'user_id' => $bob->id, 'role' => 'owner']);

        $foreign = Tag::factory()->create(['created_by' => $bob->id]);
        $alicePhoto = Photo::factory()->create(['uploaded_by' => $alice->id]);
        $alicePhoto->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $bobPhoto = Photo::factory()->create(['uploaded_by' => $bob->id]);
        $bobPhoto->children()->attach($bobChild->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
        $bobPhoto->tags()->attach($foreign->id);

        $this->actingAs($alice, 'sanctum')
            ->deleteJson("/api/photos/{$alicePhoto->id}/tags/{$foreign->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('photo_tags', ['photo_id' => $bobPhoto->id, 'tag_id' => $foreign->id]);
    });
});
