<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function createChildPivot(Photo $photo, Child $child): void
{
    $photo->children()->attach($child->id, [
        'encrypted_pek' => bin2hex(random_bytes(32)),
        'iv_pek' => bin2hex(random_bytes(16)),
    ]);
}

beforeEach(function () {
    Storage::fake('s3');
});

describe('POST /api/photos/upload', function () {
    it('uploads an encrypted photo with thumbnail and creates child pivot', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $childrenData = json_encode([
            $child->id => [
                'encrypted_pek' => bin2hex(random_bytes(32)),
                'iv_pek' => bin2hex(random_bytes(16)),
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', [
                'file' => UploadedFile::fake()->create('photo.heic', 100),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 50),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/heic',
                'size_bytes' => 100,
                'width' => 4000,
                'height' => 3000,
                'taken_at' => '2026-06-15T10:30:00Z',
                'children' => $childrenData,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id', 'uuid', 'uploaded_by', 'original_mime', 'thumbnail_path',
                    'iv_original', 'iv_thumbnail', 'size_bytes', 'width', 'height',
                    'taken_at', 'children',
                ],
            ])
            ->assertJsonPath('data.uploaded_by', $user->id)
            ->assertJsonPath('data.original_mime', 'image/heic')
            ->assertJsonCount(1, 'data.children')
            ->assertJsonPath('data.children.0.id', $child->id);

        $photoId = $response->json('data.id');
        $this->assertDatabaseHas('child_photo', [
            'child_id' => $child->id,
            'photo_id' => $photoId,
        ]);
    });

    it('uploads a photo to multiple children', function () {
        $user = User::factory()->create();
        $child1 = Child::factory()->create(['created_by' => $user->id]);
        $child2 = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child1->id, 'user_id' => $user->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child2->id, 'user_id' => $user->id, 'role' => 'owner']);

        $childrenData = json_encode([
            $child1->id => [
                'encrypted_pek' => bin2hex(random_bytes(32)),
                'iv_pek' => bin2hex(random_bytes(16)),
            ],
            $child2->id => [
                'encrypted_pek' => bin2hex(random_bytes(32)),
                'iv_pek' => bin2hex(random_bytes(16)),
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', [
                'file' => UploadedFile::fake()->create('photo.jpg', 100),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 50),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/jpeg',
                'size_bytes' => 100,
                'children' => $childrenData,
            ]);

        $response->assertStatus(201)
            ->assertJsonCount(2, 'data.children');
    });

    it('requires authentication', function () {
        $this->post('/api/photos/upload', [], ['Accept' => 'application/json'])->assertStatus(401);
    });

    it('validates required fields', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/upload', [])
            ->assertStatus(422);
    });

    it('rejects uploads to children the user does not have access to', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherChild = Child::factory()->create(['created_by' => $otherUser->id]);
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $otherUser->id, 'role' => 'owner']);

        $childrenData = json_encode([
            $otherChild->id => [
                'encrypted_pek' => bin2hex(random_bytes(32)),
                'iv_pek' => bin2hex(random_bytes(16)),
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', [
                'file' => UploadedFile::fake()->create('photo.jpg', 100),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 50),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/jpeg',
                'size_bytes' => 100,
                'children' => $childrenData,
            ])
            ->assertStatus(422);
    });
});

describe('GET /api/photos (feed)', function () {
    it('returns paginated photos across all accessible children', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo, $child);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'uuid', 'children']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 1);
    });

    it('filters by child IDs', function () {
        $user = User::factory()->create();
        $child1 = Child::factory()->create(['created_by' => $user->id]);
        $child2 = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child1->id, 'user_id' => $user->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child2->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo1 = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo1, $child1);
        $photo2 = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo2, $child2);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos?children='.$child1->id);

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    });

    it('filters by tags', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $tag = Tag::factory()->create(['name' => 'Beach']);
        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo, $child);
        $photo->tags()->attach($tag->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos?tags=Beach');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    });

    it('filters by date range', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create([
            'uploaded_by' => $user->id,
            'taken_at' => '2026-06-15 10:00:00',
        ]);
        createChildPivot($photo, $child);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos?date_from=2026-06-01&date_to=2026-06-30');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos?date_from=2026-07-01&date_to=2026-07-31');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    });

    it('requires authentication', function () {
        $this->getJson('/api/photos')->assertStatus(401);
    });
});

describe('GET /api/photos/{photo}', function () {
    it('shows a single photo with children and presigned URL', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo, $child);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'uuid', 'children', 'presigned_url'],
            ]);
    });

    it('prevents access to photos the user cannot see', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherChild = Child::factory()->create(['created_by' => $otherUser->id]);
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $otherUser->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $otherUser->id]);
        createChildPivot($photo, $otherChild);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}")
            ->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $this->getJson("/api/photos/{$photo->id}")->assertStatus(401);
    });
});

describe('DELETE /api/photos/{photo}', function () {
    it('deletes a photo the user has access to', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo, $child);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertDatabaseMissing('child_photo', ['photo_id' => $photo->id]);
    });

    it('prevents deletion of photos the user cannot access', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherChild = Child::factory()->create(['created_by' => $otherUser->id]);
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $otherUser->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $otherUser->id]);
        createChildPivot($photo, $otherChild);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $this->deleteJson("/api/photos/{$photo->id}")->assertStatus(401);
    });
});

describe('GET /api/recents', function () {
    it('returns recent uploads ordered by created_at desc', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo, $child);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/recents');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    });

    it('requires authentication', function () {
        $this->getJson('/api/recents')->assertStatus(401);
    });
});

describe('GET /api/recents/unseen-count', function () {
    it('returns count of photos since last_seen_at', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        createChildPivot($photo, $child);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/recents/unseen-count');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['unseen_count']]);
    });

    it('requires authentication', function () {
        $this->getJson('/api/recents/unseen-count')->assertStatus(401);
    });
});

describe('PUT /api/user/seen', function () {
    it('updates last_seen_at for the authenticated user', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/seen');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['last_seen_at']]);

        $this->assertNotNull($user->fresh()->last_seen_at);
    });

    it('requires authentication', function () {
        $this->putJson('/api/user/seen')->assertStatus(401);
    });
});

describe('storage quota tracking', function () {
    it('tracks storage usage incrementally on upload', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

        $childrenData = json_encode([
            $child->id => [
                'encrypted_pek' => bin2hex(random_bytes(32)),
                'iv_pek' => bin2hex(random_bytes(16)),
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', [
                'file' => UploadedFile::fake()->create('photo.heic', 1000),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 500),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/heic',
                'size_bytes' => 1000,
                'children' => $childrenData,
            ])->assertStatus(201);

        $quota = $this->actingAs($user, 'sanctum')
            ->getJson('/api/quota');

        $quota->assertJsonPath('data.used_bytes', 1536000); // 1000KB file + 500KB thumbnail
    });
});
