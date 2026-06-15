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
    it('uploads a photo with encrypted blob and thumbnail', function () {
        Storage::fake('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $file = UploadedFile::fake()->create('photo.heic', 1024);
        $thumb = UploadedFile::fake()->create('thumb.webp.enc', 600);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail' => $thumb,
                'iv_thumbnail' => str_repeat('b', 32),
                'mime_type' => 'image/heic',
                'original_extension' => 'heic',
                'size_bytes' => 1024,
                'width' => 4000,
                'height' => 3000,
                'exif_encrypted' => 'encrypted-exif-data',
                'iv_exif' => str_repeat('c', 32),
                'taken_at' => '2026-06-01T12:00:00Z',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonPath('data.uploaded_by', $user->id)
            ->assertJsonPath('data.mime_type', 'image/heic')
            ->assertJsonPath('data.size_bytes', 1024)
            ->assertJsonPath('data.width', 4000)
            ->assertJsonPath('data.height', 3000)
            ->assertJsonPath('data.thumbnail_status', 'ready')
            ->assertJsonPath('data.iv_original', str_repeat('a', 32))
            ->assertJsonPath('data.iv_thumbnail', str_repeat('b', 32));

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

        $file = UploadedFile::fake()->create('photo.heic', 1024);
        $thumb = UploadedFile::fake()->create('thumb.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail' => $thumb,
                'iv_thumbnail' => str_repeat('b', 32),
                'mime_type' => 'image/heic',
                'original_extension' => 'heic',
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

        $file = UploadedFile::fake()->create('photo.heic', 1024);
        $thumb = UploadedFile::fake()->create('thumb.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail' => $thumb,
                'iv_thumbnail' => str_repeat('b', 32),
                'mime_type' => 'image/heic',
                'original_extension' => 'heic',
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

        $file = UploadedFile::fake()->create('photo.heic', 1024);
        $thumb = UploadedFile::fake()->create('thumb.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail' => $thumb,
                'iv_thumbnail' => str_repeat('b', 32),
                'mime_type' => 'image/heic',
                'original_extension' => 'heic',
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
            ->assertJsonValidationErrors(['file', 'iv', 'thumbnail', 'iv_thumbnail', 'mime_type', 'original_extension', 'size_bytes']);
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

        $file = UploadedFile::fake()->create('photo.heic', 1024);
        $thumb = UploadedFile::fake()->create('thumb.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => 'short',
                'thumbnail' => $thumb,
                'iv_thumbnail' => str_repeat('b', 32),
                'mime_type' => 'image/heic',
                'original_extension' => 'heic',
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

        $file = UploadedFile::fake()->create('photo.heic', 1024);
        $thumb = UploadedFile::fake()->create('thumb.webp.enc', 400);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/photos/upload", [
                'file' => $file,
                'iv' => str_repeat('a', 32),
                'thumbnail' => $thumb,
                'iv_thumbnail' => str_repeat('b', 32),
                'mime_type' => 'image/heic',
                'original_extension' => 'heic',
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

describe('GET /api/children/{child}/photos/{photo}/thumbnail', function () {
    it('returns a presigned URL for the thumbnail', function () {
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
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['presigned_url']]);
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
            ->getJson("/api/children/{$child->id}/photos/{$photo->id}/thumbnail");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();

        $response = $this->getJson("/api/children/{$photo->child_id}/photos/{$photo->id}/thumbnail");

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

describe('DELETE /api/children/{child}/photos/{photo}', function () {
    it('allows owner to delete a photo', function () {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 5000]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
        ]);

        $disk->put($photo->original_path, str_repeat('a', 1000));
        $disk->put($photo->thumbnail_path, str_repeat('b', 400));

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);

        $disk->assertMissing($photo->original_path);
        $disk->assertMissing($photo->thumbnail_path);

        expect((int) $child->storageUsage->fresh()->used_bytes)->toBeLessThan(5000);
    });

    it('allows admin to delete a photo', function () {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $admin->id, 'role' => 'admin']);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 2000]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $owner->id,
        ]);

        $disk->put($photo->original_path, str_repeat('a', 500));
        $disk->put($photo->thumbnail_path, str_repeat('b', 200));

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
    });

    it('allows uploader with member role to delete their own photo', function () {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $owner = User::factory()->create();
        $member = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member']);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 2000]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $member->id,
        ]);

        $disk->put($photo->original_path, str_repeat('a', 500));
        $disk->put($photo->thumbnail_path, str_repeat('b', 200));

        $response = $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
    });

    it('prevents non-uploader member from deleting another members photo', function () {
        Storage::fake('s3');

        $owner = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member1->id, 'role' => 'member']);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member2->id, 'role' => 'member']);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $member1->id,
        ]);

        $response = $this->actingAs($member2, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    });

    it('cascades deletion of photo_tags associations', function () {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 2000]);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $user->id,
        ]);

        $tag = Tag::factory()->create(['child_id' => $child->id, 'name' => 'Spain 2026']);
        $photo->tags()->attach($tag->id);

        $disk->put($photo->original_path, str_repeat('a', 500));
        $disk->put($photo->thumbnail_path, str_repeat('b', 200));

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag->id]);
    });

    it('prevents non-members from deleting photos', function () {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $photo = Photo::factory()->create([
            'child_id' => $child->id,
            'uploaded_by' => $owner->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/children/{$child->id}/photos/{$photo->id}");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();

        $response = $this->deleteJson("/api/children/{$photo->child_id}/photos/{$photo->id}");

        $response->assertStatus(401);
    });
});
