<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\Tag;
use App\Models\UploadIntent;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\StorageService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToRetrieveMetadata;

function directUploadSetup(): array
{
    $disk = Storage::fake('s3');
    $disk->buildTemporaryUploadUrlsUsing(fn (string $path) => [
        'url' => 'https://bucket.example-storage.test/'.$path.'?X-Amz-Signature=put',
        'headers' => ['Host' => ['bucket.example-storage.test']],
    ]);

    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

    return [$user, $child, $disk];
}

function intentPayload(array $overrides = []): array
{
    return array_merge(['original_bytes' => 1000, 'thumbnail_bytes' => 100, 'original_mime' => 'image/jpeg'], $overrides);
}

function completePayload(Child $child, array $overrides = []): array
{
    return array_merge([
        'iv' => bin2hex(random_bytes(16)),
        'iv_thumbnail' => bin2hex(random_bytes(16)),
        'original_mime' => 'image/jpeg',
        'size_bytes' => 900,
        'children' => json_encode([$child->id => [
            'encrypted_pek' => bin2hex(random_bytes(32)),
            'iv_pek' => bin2hex(random_bytes(16)),
        ]]),
    ], $overrides);
}

/** Create an intent through the API and simulate the browser PUTs. */
function openIntent($test, User $user, $disk, array $overrides = [], bool $put = true): UploadIntent
{
    $uuid = $test->actingAs($user, 'sanctum')
        ->postJson('/api/photos/upload-intent', intentPayload($overrides))
        ->assertCreated()
        ->json('data.uuid');

    $intent = UploadIntent::where('uuid', $uuid)->firstOrFail();
    if ($put) {
        $disk->put($intent->original_path, str_repeat('o', $intent->expected_original_bytes));
        $disk->put($intent->thumbnail_path, str_repeat('t', $intent->expected_thumbnail_bytes));
    }

    return $intent;
}

describe('upload intent', function () {
    it('reserves quota and returns signed URLs without a Host header', function () {
        [$user] = directUploadSetup();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/upload-intent', intentPayload())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['uuid', 'original' => ['url', 'headers'], 'thumbnail' => ['url', 'headers'], 'expires_at']]);

        $intent = UploadIntent::firstOrFail();
        expect($intent->user_id)->toBe($user->id)
            ->and($intent->reserved_bytes)->toBe(1100)
            ->and($intent->original_path)->toBe("photos/{$intent->uuid}.jpg.enc")
            ->and($intent->thumbnail_path)->toBe("photos/{$intent->uuid}_thumb.webp.enc")
            ->and($response->json('data.original.url'))->toContain($intent->original_path)
            ->and(array_change_key_case($response->json('data.original.headers')))
            ->not->toHaveKey('host')
            ->toHaveKey('cache-control')
            ->toHaveKey('content-type')
            ->and((int) StorageUsage::global()->used_bytes)->toBe(0);
    });

    it('rejects over-quota requests and counts earlier reservations', function () {
        [$user] = directUploadSetup();
        config(['avapeek.storage.limit_bytes' => 2000]);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())
            ->assertStatus(413)->assertJson(['message' => 'Storage quota exceeded.']);

        expect(UploadIntent::count())->toBe(1);
    });

    it('ignores expired reservations when checking quota', function () {
        [$user] = directUploadSetup();
        config(['avapeek.storage.limit_bytes' => 1500]);
        UploadIntent::factory()->expired()->create(['reserved_bytes' => 1400]);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())->assertCreated();
    });

    it('counts completed usage together with reservations', function () {
        [$user] = directUploadSetup();
        config(['avapeek.storage.limit_bytes' => 1500]);
        StorageUsage::global()->update(['used_bytes' => 1000]);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())->assertStatus(413);
    });

    it('allows reservation when the limit is unknown', function () {
        [$user] = directUploadSetup();
        config(['avapeek.storage.limit_bytes' => null, 'avapeek.storage.volume_path' => null]);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload(['original_bytes' => 52428800]))->assertCreated();
    });

    it('validates sizes and mime', function () {
        [$user] = directUploadSetup();

        foreach ([
            ['original_bytes' => 52428801],
            ['original_bytes' => 0],
            ['thumbnail_bytes' => 5242881],
            ['original_mime' => 'application/pdf'],
        ] as $bad) {
            $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload($bad))->assertUnprocessable();
        }
    });

    it('requires authentication', function () {
        $this->postJson('/api/photos/upload-intent', intentPayload())->assertUnauthorized();
        $this->postJson('/api/photos/'.fake()->uuid().'/complete', [])->assertUnauthorized();
    });

    it('returns 404 when direct uploads are disabled', function () {
        [$user] = directUploadSetup();
        config(['avapeek.uploads.direct' => false]);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())->assertNotFound();
        expect(UploadIntent::count())->toBe(0);
    });

    it('uses its own rate limiter', function () {
        [$user] = directUploadSetup();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload());
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())->assertStatus(429);
    });
});

describe('complete upload', function () {
    it('creates the photo, wraps, tags and usage, and deletes the intent', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", $payload = completePayload($child, ['tag_ids' => [$tag->id]]))
            ->assertCreated();

        $photo = Photo::firstOrFail();
        expect($photo->uuid)->toBe($intent->uuid)
            ->and((int) $photo->stored_bytes)->toBe(1100)
            ->and($photo->iv_original)->toBe($payload['iv'])
            ->and($photo->children()->count())->toBe(1)
            ->and($photo->tags()->pluck('tags.id')->all())->toBe([$tag->id])
            ->and((int) StorageUsage::global()->used_bytes)->toBe(1100)
            ->and(UploadIntent::count())->toBe(0);
        $disk->assertExists($intent->original_path);
        $response->assertJsonStructure(['data' => ['id', 'uuid', 'original_path', 'thumbnail_path', 'iv_original', 'iv_thumbnail', 'size_bytes', 'tags', 'children', 'comment_count']]);
    });

    it('returns the same response shape as the proxy upload', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);

        $direct = $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertCreated()->json('data');

        $proxy = $this->actingAs($user, 'sanctum')->post('/api/photos/upload', array_merge(completePayload($child), [
            'file' => UploadedFile::fake()->create('p.jpg', 1),
            'thumbnail' => UploadedFile::fake()->create('t.webp', 1),
        ]))->assertCreated()->json('data');

        expect(array_keys($direct))->toBe(array_keys($proxy));
    });

    it('answers a retry after a lost response idempotently with the existing photo', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $payload = completePayload($child);

        $first = $this->actingAs($user, 'sanctum')->postJson("/api/photos/{$intent->uuid}/complete", $payload)->assertCreated();
        $retry = $this->actingAs($user, 'sanctum')->postJson("/api/photos/{$intent->uuid}/complete", $payload)->assertOk();

        expect($retry->json('data.id'))->toBe($first->json('data.id'))
            ->and(array_keys($retry->json('data')))->toBe(array_keys($first->json('data')))
            ->and(Photo::count())->toBe(1)
            ->and((int) StorageUsage::global()->used_bytes)->toBe(1100);
        $disk->assertExists($intent->original_path);
    });

    it('gives another user a 404 for a completed upload uuid', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $this->actingAs($user, 'sanctum')->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))->assertCreated();
        $other = User::factory()->create();
        $otherChild = Child::factory()->create(['created_by' => $other->id]);
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $other->id, 'role' => 'owner']);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($otherChild))
            ->assertNotFound();

        $disk->assertExists($intent->original_path);
        expect(Photo::count())->toBe(1);
    });

    it('rejects a size mismatch, deletes objects and releases the reservation', function () {
        [$user, $child, $disk] = directUploadSetup();
        config(['avapeek.storage.limit_bytes' => 1200]);
        $intent = openIntent($this, $user, $disk);
        $disk->put($intent->original_path, str_repeat('o', 5000));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertUnprocessable();

        expect($disk->allFiles())->toBe([])
            ->and(UploadIntent::count())->toBe(0)
            ->and(Photo::count())->toBe(0)
            ->and((int) StorageUsage::global()->used_bytes)->toBe(0);
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())->assertCreated();
    });

    it('rejects when objects were never uploaded', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk, put: false);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertUnprocessable();

        expect(UploadIntent::count())->toBe(0)->and(Photo::count())->toBe(0);
    });

    it('returns 404 to another user and leaves the intent alone', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $other = User::factory()->create();
        $otherChild = Child::factory()->create(['created_by' => $other->id]);
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $other->id, 'role' => 'owner']);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($otherChild))
            ->assertNotFound();

        expect(UploadIntent::count())->toBe(1);
        $disk->assertExists($intent->original_path);
    });

    it('returns 404 for an expired intent', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $intent->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertNotFound();
    });

    it('returns 422 for a non-member child like the proxy upload and cleans up', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $foreign = Child::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, [
                'children' => json_encode([$foreign->id => ['encrypted_pek' => 'a', 'iv_pek' => 'b']]),
            ]))
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => "Invalid child IDs: {$foreign->id}"]);

        expect($disk->allFiles())->toBe([])->and(UploadIntent::count())->toBe(0)->and(Photo::count())->toBe(0);
    });

    it('rejects a mime that differs from the intent', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, ['original_mime' => 'image/png']))
            ->assertUnprocessable();
    });

    it('validates metadata with the same rules as upload', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, ['iv' => 'short', 'children' => '{}']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['iv', 'children']);
    });

    it('rolls back photo, child_photo, photo_tags and usage, removes blobs and the intent when the last db step fails', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id]);
        $reservedUsage = (int) StorageUsage::global()->used_bytes;

        // Fires after the photo insert, the child attach and the tag sync.
        $this->partialMock(QuotaService::class, function ($mock) {
            $mock->shouldReceive('addUsage')->once()->andThrow(new RuntimeException('forced failure'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, ['tag_ids' => [$tag->id]]));
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('forced failure');
        }

        expect($disk->allFiles())->toBe([])
            ->and(Photo::count())->toBe(0)
            ->and(DB::table('child_photo')->count())->toBe(0)
            ->and(DB::table('photo_tags')->count())->toBe(0)
            ->and(UploadIntent::count())->toBe(0)
            ->and((int) StorageUsage::global()->used_bytes)->toBe($reservedUsage);
    });

    it('answers 422, cleans blobs and the intent and persists nothing when a tag is deleted between validation and sync', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id]);

        Photo::created(function () use ($tag) {
            $tag->delete();
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, ['tag_ids' => [$tag->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tag_ids']);

        expect($disk->allFiles())->toBe([])
            ->and(Photo::count())->toBe(0)
            ->and(DB::table('child_photo')->count())->toBe(0)
            ->and(DB::table('photo_tags')->count())->toBe(0)
            ->and(UploadIntent::count())->toBe(0);
    });
});

describe('uploads:prune-intents', function () {
    it('deletes objects and rows of expired intents only', function () {
        [, , $disk] = directUploadSetup();
        $expired = UploadIntent::factory()->expired()->create();
        $live = UploadIntent::factory()->create();
        foreach ([$expired, $live] as $intent) {
            $disk->put($intent->original_path, 'x');
            $disk->put($intent->thumbnail_path, 'y');
        }

        $this->artisan('uploads:prune-intents')->assertSuccessful();

        $disk->assertMissing($expired->original_path);
        $disk->assertMissing($expired->thumbnail_path);
        $disk->assertExists($live->original_path);
        expect(UploadIntent::pluck('id')->all())->toBe([$live->id]);
    });

    it('is scheduled every fifteen minutes', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'uploads:prune-intents'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');
    });
});

describe('storage:reconcile with intents', function () {
    it('ignores live-intent keys past grace but still flags true orphans', function () {
        [, , $disk] = directUploadSetup();
        $intent = UploadIntent::factory()->create();
        $orphan = 'photos/44444444-4444-4444-4444-444444444444.jpg.enc';
        foreach ([$intent->original_path, $intent->thumbnail_path, $orphan] as $path) {
            $disk->put($path, 'x');
            touch($disk->path($path), time() - 7200);
        }

        $this->artisan('storage:reconcile --delete-orphans')->assertSuccessful();

        $disk->assertExists($intent->original_path);
        $disk->assertExists($intent->thumbnail_path);
        $disk->assertMissing($orphan);
    });
});

describe('complete under concurrency and storage errors', function () {
    it('never deletes blobs of a photo that a parallel complete already committed', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);

        // State a losing request sees: the winner's photo row exists for this uuid
        // while the loser still holds the intent (it read it before the winner committed).
        $photo = Photo::factory()->create([
            'uuid' => $intent->uuid,
            'uploaded_by' => $user->id,
            'original_path' => $intent->original_path,
            'thumbnail_path' => $intent->thumbnail_path,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertStatus(409);

        $disk->assertExists($photo->original_path);
        $disk->assertExists($photo->thumbnail_path);
        expect(Photo::count())->toBe(1);
    });

    it('does not delete live blobs when discarding an intent that has a photo', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        Photo::factory()->create([
            'uuid' => $intent->uuid,
            'uploaded_by' => $user->id,
            'original_path' => $intent->original_path,
            'thumbnail_path' => $intent->thumbnail_path,
        ]);
        $disk->put($intent->original_path, 'tampered-size');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertUnprocessable();

        $disk->assertExists($intent->original_path);
        $disk->assertExists($intent->thumbnail_path);
    });

    it('keeps blobs and intent and answers 503 on a transient storage error', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);
        $this->partialMock(StorageService::class, function ($mock) {
            $mock->shouldReceive('objectSize')->andThrow(new RuntimeException('timeout'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertStatus(503);

        $disk->assertExists($intent->original_path);
        $disk->assertExists($intent->thumbnail_path);
        expect(UploadIntent::count())->toBe(1)->and(Photo::count())->toBe(0);
    });

    it('objectSize returns null only for not-found and rethrows other errors', function () {
        $disk = Storage::fake('s3');
        $disk->put('photos/a', 'abc');
        $service = app(StorageService::class);

        expect($service->objectSize('photos/a'))->toBe(3)
            ->and($service->objectSize('photos/missing'))->toBeNull();

        $operator = Mockery::mock(FilesystemOperator::class);
        $operator->shouldReceive('fileSize')->andThrow(UnableToRetrieveMetadata::fileSize('photos/a'));
        $operator->shouldReceive('fileExists')->andThrow(UnableToCheckFileExistence::forLocation('photos/a'));
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('getDriver')->andReturn($operator);
        Storage::set('s3', $mock);

        expect(fn () => $service->objectSize('photos/a'))->toThrow(UnableToCheckFileExistence::class);
    });
});

describe('pending intent cap', function () {
    it('returns 429 when a user has too many live intents and ignores expired ones', function () {
        [$user, $child] = directUploadSetup();
        config(['avapeek.uploads.max_pending_intents' => 2]);
        UploadIntent::factory()->expired()->create(['user_id' => $user->id]);
        UploadIntent::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())
            ->assertStatus(429);

        $other = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $other->id, 'role' => 'member']);
        $this->actingAs($other, 'sanctum')->postJson('/api/photos/upload-intent', intentPayload())
            ->assertCreated();
    });
});

describe('complete upload tags', function () {
    it('rejects an unknown tag, a foreign tag and a tag of an unlinked child with the same 422', function () {
        [$user, $child, $disk] = directUploadSetup();
        $otherChild = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $user->id, 'role' => 'owner']);
        $foreign = Tag::factory()->create();
        $unlinked = Tag::factory()->create(['child_id' => $otherChild->id]);

        foreach ([99999, $foreign->id, $unlinked->id] as $tagId) {
            $intent = openIntent($this, $user, $disk);
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, ['tag_ids' => [$tagId]]))
                ->assertStatus(422)
                ->assertJsonPath('message', 'One or more tags are invalid.');
        }

        expect(Photo::count())->toBe(0);
    });

    it('rejects the old plaintext tags field', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child, ['tags' => ['beach']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags']);
    });
});

describe('locked failure cleanup and pruning', function () {
    it('keeps blobs when a parallel complete commits a photo between the failure and its cleanup', function () {
        [$user, $child, $disk] = directUploadSetup();
        $intent = openIntent($this, $user, $disk);

        Photo::created(function () {
            throw new RuntimeException('forced failure');
        });
        // Interleaving: right after our transaction rolls back, a parallel request commits a photo.
        $injected = false;
        // Level 1 is the test's own wrapper transaction, i.e. complete()'s transaction is fully rolled back.
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use (&$injected, $intent, $user) {
            if ($injected || $event->connection->transactionLevel() !== 1) {
                return;
            }
            $injected = true;
            Photo::factory()->create([
                'uuid' => $intent->uuid,
                'uploaded_by' => $user->id,
                'original_path' => $intent->original_path,
                'thumbnail_path' => $intent->thumbnail_path,
            ]);
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$intent->uuid}/complete", completePayload($child))
            ->assertStatus(409);

        $disk->assertExists($intent->original_path);
        $disk->assertExists($intent->thumbnail_path);
        expect(Photo::count())->toBe(1);
    });

    it('prune keeps blobs of a completed photo but removes the stale intent row', function () {
        [, , $disk] = directUploadSetup();
        $intent = UploadIntent::factory()->expired()->create();
        Photo::factory()->create([
            'uuid' => $intent->uuid,
            'original_path' => $intent->original_path,
            'thumbnail_path' => $intent->thumbnail_path,
        ]);
        $disk->put($intent->original_path, 'x');
        $disk->put($intent->thumbnail_path, 'y');

        $this->artisan('uploads:prune-intents')->assertSuccessful();

        $disk->assertExists($intent->original_path);
        $disk->assertExists($intent->thumbnail_path);
        expect(UploadIntent::count())->toBe(0);
    });
});
