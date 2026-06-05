<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('POST /api/children/{child}/photos/upload', function () {
    it('uploads a photo with encrypted blobs and thumbnails', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $file = UploadedFile::fake()->create('photo.webp.enc', 1024);
        $sm = UploadedFile::fake()->create('thumb_sm.webp.enc', 100);
        $md = UploadedFile::fake()->create('thumb_md.webp.enc', 300);
        $lg = UploadedFile::fake()->create('thumb_lg.webp.enc', 600);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail_sm' => $sm,
                'iv_sm' => str_repeat('b', 32),
                'thumbnail_md' => $md,
                'iv_md' => str_repeat('c', 32),
                'thumbnail_lg' => $lg,
                'iv_lg' => str_repeat('d', 32),
                'mime_type' => 'image/webp',
                'size_bytes' => 1024,
                'width' => 4000,
                'height' => 3000,
                'exif_encrypted' => 'encrypted-exif-data',
                'iv_exif' => str_repeat('e', 32),
                'taken_at' => '2026-06-01T12:00:00Z',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonPath('data.uploaded_by', $user->id)
            ->assertJsonPath('data.mime_type', 'image/webp')
            ->assertJsonPath('data.size_bytes', 1024)
            ->assertJsonPath('data.width', 4000)
            ->assertJsonPath('data.height', 3000)
            ->assertJsonPath('data.thumbnail_status', 'ready')
            ->assertJsonPath('data.iv_original', str_repeat('a', 32))
            ->assertJsonPath('data.iv_sm', str_repeat('b', 32))
            ->assertJsonPath('data.iv_md', str_repeat('c', 32))
            ->assertJsonPath('data.iv_lg', str_repeat('d', 32));

        $this->assertDatabaseHas('photos', [
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_status' => 'ready',
        ]);

        $storageUsage = $child->storageUsage->fresh();
        expect((int) $storageUsage->used_bytes)->toBeGreaterThan(0);
    });

    it('creates tags from tag names during upload', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $file = UploadedFile::fake()->create('photo.webp.enc', 1024);
        $sm = UploadedFile::fake()->create('thumb_sm.webp.enc', 100);
        $md = UploadedFile::fake()->create('thumb_md.webp.enc', 200);
        $lg = UploadedFile::fake()->create('thumb_lg.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail_sm' => $sm,
                'iv_sm' => str_repeat('b', 32),
                'thumbnail_md' => $md,
                'iv_md' => str_repeat('c', 32),
                'thumbnail_lg' => $lg,
                'iv_lg' => str_repeat('d', 32),
                'mime_type' => 'image/webp',
                'size_bytes' => 1024,
                'tags' => ['Spain 2026', 'Beach'],
            ]);

        $response->assertStatus(201)
            ->assertJsonCount(2, 'data.tags');

        $this->assertDatabaseHas('tags', [
            'child_id' => $child->id,
            'name' => 'Spain 2026',
        ]);
        $this->assertDatabaseHas('tags', [
            'child_id' => $child->id,
            'name' => 'Beach',
        ]);

        $photoId = $response->json('data.id');
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photoId]);
    });

    it('reuses existing tags with the same name during upload', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        Tag::factory()->create(['child_id' => $child->id, 'name' => 'Spain 2026']);

        $file = UploadedFile::fake()->create('photo.webp.enc', 1024);
        $sm = UploadedFile::fake()->create('thumb_sm.webp.enc', 100);
        $md = UploadedFile::fake()->create('thumb_md.webp.enc', 200);
        $lg = UploadedFile::fake()->create('thumb_lg.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail_sm' => $sm,
                'iv_sm' => str_repeat('b', 32),
                'thumbnail_md' => $md,
                'iv_md' => str_repeat('c', 32),
                'thumbnail_lg' => $lg,
                'iv_lg' => str_repeat('d', 32),
                'mime_type' => 'image/webp',
                'size_bytes' => 1024,
                'tags' => ['Spain 2026'],
            ]);

        $response->assertStatus(201);

        $this->assertEquals(1, Tag::where('child_id', $child->id)->where('name', 'Spain 2026')->count());
    });

    it('returns 413 when quota is exceeded', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 5 * 1024 * 1024 * 1024 - 100]);

        $file = UploadedFile::fake()->create('photo.webp.enc', 1024);
        $sm = UploadedFile::fake()->create('thumb_sm.webp.enc', 100);
        $md = UploadedFile::fake()->create('thumb_md.webp.enc', 200);
        $lg = UploadedFile::fake()->create('thumb_lg.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail_sm' => $sm,
                'iv_sm' => str_repeat('b', 32),
                'thumbnail_md' => $md,
                'iv_md' => str_repeat('c', 32),
                'thumbnail_lg' => $lg,
                'iv_lg' => str_repeat('d', 32),
                'mime_type' => 'image/webp',
                'size_bytes' => 1024,
            ]);

        $response->assertStatus(413);
    });

    it('validates required fields', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'iv', 'thumbnail_sm', 'iv_sm', 'thumbnail_md', 'iv_md', 'thumbnail_lg', 'iv_lg', 'mime_type', 'size_bytes']);
    });

    it('validates IV length is exactly 32', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $file = UploadedFile::fake()->create('photo.webp.enc', 1024);
        $sm = UploadedFile::fake()->create('thumb_sm.webp.enc', 100);
        $md = UploadedFile::fake()->create('thumb_md.webp.enc', 200);
        $lg = UploadedFile::fake()->create('thumb_lg.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => 'short',
                'thumbnail_sm' => $sm,
                'iv_sm' => str_repeat('b', 32),
                'thumbnail_md' => $md,
                'iv_md' => str_repeat('c', 32),
                'thumbnail_lg' => $lg,
                'iv_lg' => str_repeat('d', 32),
                'mime_type' => 'image/webp',
                'size_bytes' => 1024,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iv']);
    });

    it('prevents non-members from uploading', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $file = UploadedFile::fake()->create('photo.webp.enc', 1024);
        $sm = UploadedFile::fake()->create('thumb_sm.webp.enc', 100);
        $md = UploadedFile::fake()->create('thumb_md.webp.enc', 200);
        $lg = UploadedFile::fake()->create('thumb_lg.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail_sm' => $sm,
                'iv_sm' => str_repeat('b', 32),
                'thumbnail_md' => $md,
                'iv_md' => str_repeat('c', 32),
                'thumbnail_lg' => $lg,
                'iv_lg' => str_repeat('d', 32),
                'mime_type' => 'image/webp',
                'size_bytes' => 1024,
            ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->postJson("/api/children/{$child->id}/photos/upload", []);

        $response->assertStatus(401);
    });
});

describe('GET /api/children/{child}/photos', function () {
    it('returns paginated photos for a child', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        Photo::factory()->count(3)->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'child_id', 'uploaded_by', 'tags']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    });

    it('includes tags with each photo', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Spain 2026']);
        $photo->tags()->attach($tag->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.tags.0.name', 'Spain 2026');
    });

    it('filters photos by tag name', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $spain = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Spain 2026']);
        $beach = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Beach']);

        $photo1 = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $photo1->tags()->attach($spain->id);

        $photo2 = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $user->id]);
        $photo2->tags()->attach($beach->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos?tag=Spain%202026");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $photo1->id);
    });

    it('filters photos by month', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'taken_at' => '2026-06-15 12:00:00',
        ]);
        Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'taken_at' => '2026-05-10 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos?month=2026-06");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    });

    it('returns empty when no photos exist', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('prevents non-members from listing photos', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->getJson("/api/children/{$child->id}/photos");

        $response->assertStatus(401);
    });
});

describe('GET /api/children/{child}/photos/{photo}', function () {
    it('returns photo details with a presigned URL', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'original_path' => 'test/photo.webp.enc',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $photo->id)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonStructure([
                'data' => ['id', 'presigned_url', 'tags'],
            ]);
    });

    it('prevents non-members from viewing photo', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $photo = Photo::factory()->create(['child_id' => $child->id, 'uploaded_by' => $owner->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();

        $response = $this->getJson("/api/children/{$photo->child_id}/photos/{$photo->id}");

        $response->assertStatus(401);
    });
});

describe('GET /api/children/{child}/photos/{photo}/thumbnail/{size}', function () {
    it('returns a presigned URL for a small thumbnail', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_sm_path' => 'test/thumb_sm.webp.enc',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail/sm");

        $response->assertStatus(200)
            ->assertJsonPath('data.size', 'sm')
            ->assertJsonStructure(['data' => ['size', 'presigned_url']]);
    });

    it('returns a presigned URL for a medium thumbnail', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_md_path' => 'test/thumb_md.webp.enc',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail/md");

        $response->assertStatus(200)
            ->assertJsonPath('data.size', 'md');
    });

    it('returns a presigned URL for a large thumbnail', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
            'thumbnail_lg_path' => 'test/thumb_lg.webp.enc',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail/lg");

        $response->assertStatus(200)
            ->assertJsonPath('data.size', 'lg');
    });

    it('returns 422 for an invalid thumbnail size', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail/xl");

        $response->assertStatus(422);
    });

    it('prevents non-members from accessing thumbnails', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $owner->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail/sm");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();

        $response = $this->getJson("/api/children/{$photo->child_id}/photos/{$photo->id}/thumbnail/sm");

        $response->assertStatus(401);
    });
});

describe('GET /api/storage/disk-info', function () {
    it('returns disk information', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/storage/disk-info');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['total_bytes', 'used_bytes', 'free_bytes'],
            ]);
    });

    it('requires authentication', function () {
        $response = $this->getJson('/api/storage/disk-info');

        $response->assertStatus(401);
    });
});