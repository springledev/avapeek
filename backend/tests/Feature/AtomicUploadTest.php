<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function atomicUploadSetup(): array
{
    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

    return [$user, $child];
}

function atomicUploadPayload(Child $child): array
{
    return [
        'file' => UploadedFile::fake()->create('photo.heic', 1),
        'iv' => bin2hex(random_bytes(16)),
        'thumbnail' => UploadedFile::fake()->create('thumb.webp', 1),
        'iv_thumbnail' => bin2hex(random_bytes(16)),
        'original_mime' => 'image/heic',
        'size_bytes' => 1024,
        'children' => json_encode([$child->id => [
            'encrypted_pek' => bin2hex(random_bytes(32)),
            'iv_pek' => bin2hex(random_bytes(16)),
        ]]),
    ];
}

describe('atomic upload', function () {
    it('stores stored_bytes and increments quota', function () {
        Storage::fake('s3');
        [$user, $child] = atomicUploadSetup();

        $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', atomicUploadPayload($child))
            ->assertCreated();

        expect((int) Photo::first()->stored_bytes)->toBe(2048)
            ->and((int) StorageUsage::global()->used_bytes)->toBe(2048);
    });

    it('removes blobs and leaves quota untouched when a db step fails', function () {
        Storage::fake('s3');
        [$user, $child] = atomicUploadSetup();

        Photo::created(function () {
            throw new RuntimeException('forced failure');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user, 'sanctum')
                ->post('/api/photos/upload', atomicUploadPayload($child));
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('forced failure');
        }

        expect(Storage::disk('s3')->allFiles())->toBe([])
            ->and(Photo::count())->toBe(0)
            ->and((int) StorageUsage::global()->used_bytes)->toBe(0);
    });
});

describe('delete uses stored_bytes', function () {
    it('subtracts stored_bytes without calling size()', function () {
        $disk = Storage::fake('s3');
        [$user, $child] = atomicUploadSetup();
        $photo = Photo::factory()->create(['uploaded_by' => $user->id, 'stored_bytes' => 700]);
        $photo->children()->attach($child->id, ['encrypted_pek' => 'a', 'iv_pek' => 'b']);
        StorageUsage::global()->update(['used_bytes' => 1000]);
        $disk->put($photo->original_path, 'x');
        $disk->put($photo->thumbnail_path, 'y');

        $spy = Mockery::spy($disk);
        $spy->shouldNotReceive('size');
        Storage::set('s3', $spy);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}")
            ->assertNoContent();

        expect((int) StorageUsage::global()->used_bytes)->toBe(300);
    });
});

describe('storage:reconcile', function () {
    it('fixes drifted usage and lists orphans without deleting', function () {
        $disk = Storage::fake('s3');
        $photo = Photo::factory()->create(['stored_bytes' => 500]);
        StorageUsage::global()->update(['used_bytes' => 9999]);
        $disk->put($photo->original_path, 'x');
        $disk->put($photo->thumbnail_path, 'y');
        $disk->put('photos/11111111-1111-1111-1111-111111111111.jpg.enc', 'z');
        touch($disk->path('photos/11111111-1111-1111-1111-111111111111.jpg.enc'), time() - 7200);

        $this->artisan('storage:reconcile')
            ->expectsOutputToContain('photos/11111111-1111-1111-1111-111111111111.jpg.enc')
            ->assertSuccessful();

        expect((int) StorageUsage::global()->used_bytes)->toBe(500);
        $disk->assertExists('photos/11111111-1111-1111-1111-111111111111.jpg.enc');
        $disk->assertExists($photo->original_path);
    });

    it('deletes old orphans only with the flag and respects grace', function () {
        $disk = Storage::fake('s3');
        $photo = Photo::factory()->create(['stored_bytes' => 1]);
        $disk->put($photo->original_path, 'x');
        $disk->put($photo->thumbnail_path, 'y');
        $disk->put('photos/22222222-2222-2222-2222-222222222222.jpg.enc', 'z');
        $disk->put('backups/db.sql', 'z');
        $disk->put('other/file.bin', 'z');
        $disk->put('photos/not-a-photo-key.txt', 'z');
        foreach (['backups/db.sql', 'other/file.bin', 'photos/not-a-photo-key.txt'] as $p) {
            touch($disk->path($p), time() - 7200);
        }
        $disk->put('photos/33333333-3333-3333-3333-333333333333.jpg.enc', 'z');
        touch($disk->path('photos/22222222-2222-2222-2222-222222222222.jpg.enc'), time() - 7200);

        $this->artisan('storage:reconcile --delete-orphans')->assertSuccessful();

        $disk->assertMissing('photos/22222222-2222-2222-2222-222222222222.jpg.enc');
        $disk->assertExists('photos/33333333-3333-3333-3333-333333333333.jpg.enc');
        $disk->assertExists($photo->original_path);
        $disk->assertExists($photo->thumbnail_path);
        $disk->assertExists('backups/db.sql');
        $disk->assertExists('other/file.bin');
        $disk->assertExists('photos/not-a-photo-key.txt');
    });
});

describe('failed blob writes', function () {
    it('rolls back and cleans the original when the thumbnail put fails', function () {
        $real = Storage::fake('s3');
        [$user, $child] = atomicUploadSetup();

        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('put')->withArgs(fn ($path) => str_contains($path, '_thumb'))->andReturn(false);
        $mock->shouldReceive('put')->withArgs(fn ($path) => ! str_contains($path, '_thumb'))->andReturnUsing(
            fn ($path, $content) => $real->put($path, $content)
        );
        Storage::set('s3', $mock);

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', atomicUploadPayload($child)))
            ->toThrow(RuntimeException::class);

        expect($real->allFiles())->toBe([])
            ->and(Photo::count())->toBe(0)
            ->and((int) StorageUsage::global()->used_bytes)->toBe(0);
    });
});
