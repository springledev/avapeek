<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\UploadIntent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function nameUploadPayload(Child $child, array $overrides = []): array
{
    return array_merge([
        'file' => UploadedFile::fake()->create('photo.jpg', 10),
        'thumbnail' => UploadedFile::fake()->create('thumb.webp', 5),
        'iv' => bin2hex(random_bytes(16)),
        'iv_thumbnail' => bin2hex(random_bytes(16)),
        'original_mime' => 'image/jpeg',
        'size_bytes' => 100,
        'children' => json_encode([$child->id => [
            'encrypted_pek' => bin2hex(random_bytes(32)),
            'iv_pek' => bin2hex(random_bytes(16)),
        ]]),
    ], $overrides);
}

function nameFixture(): array
{
    Storage::fake('s3');
    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

    return [$user, $child];
}

describe('encrypted original filename', function () {
    it('stores and returns name_encrypted and iv_name on proxy upload', function () {
        [$user, $child] = nameFixture();
        $iv = bin2hex(random_bytes(16));

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/photos/upload', nameUploadPayload($child, ['name_encrypted' => 'Y2lwaGVy', 'iv_name' => $iv]))
            ->assertCreated();

        $response->assertJsonPath('data.name_encrypted', 'Y2lwaGVy')->assertJsonPath('data.iv_name', $iv);
        expect(Photo::firstOrFail()->name_encrypted)->toBe('Y2lwaGVy');
    });

    it('stores and returns the name on direct upload completion', function () {
        [$user, $child] = nameFixture();
        $disk = Storage::disk('s3');
        $disk->buildTemporaryUploadUrlsUsing(fn (string $path) => [
            'url' => 'https://bucket.example-storage.test/'.$path,
            'headers' => ['Host' => ['bucket.example-storage.test']],
        ]);
        $uuid = $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/upload-intent', ['original_bytes' => 1000, 'thumbnail_bytes' => 100, 'original_mime' => 'image/jpeg'])
            ->assertCreated()->json('data.uuid');
        $intent = UploadIntent::where('uuid', $uuid)->firstOrFail();
        $disk->put($intent->original_path, str_repeat('o', $intent->expected_original_bytes));
        $disk->put($intent->thumbnail_path, str_repeat('t', $intent->expected_thumbnail_bytes));
        $payload = collect(nameUploadPayload($child, ['name_encrypted' => 'Y2lwaGVy', 'iv_name' => str_repeat('c', 32)]))
            ->except(['file', 'thumbnail'])->all();

        $this->actingAs($user, 'sanctum')->postJson("/api/photos/{$uuid}/complete", $payload)
            ->assertCreated()
            ->assertJsonPath('data.name_encrypted', 'Y2lwaGVy')
            ->assertJsonPath('data.iv_name', str_repeat('c', 32));
    });

    it('validates iv_name size and requires it with name_encrypted', function () {
        [$user, $child] = nameFixture();

        $this->actingAs($user, 'sanctum')->post('/api/photos/upload', nameUploadPayload($child, ['name_encrypted' => 'abc']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('iv_name');
        $this->actingAs($user, 'sanctum')->post('/api/photos/upload', nameUploadPayload($child, ['name_encrypted' => 'abc', 'iv_name' => 'short']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('iv_name');
        $this->actingAs($user, 'sanctum')->post('/api/photos/upload', nameUploadPayload($child, ['name_encrypted' => str_repeat('a', 1025), 'iv_name' => str_repeat('a', 32)]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('name_encrypted');
    });

    it('keeps the name null when not sent and has no plaintext name column', function () {
        [$user, $child] = nameFixture();

        $this->actingAs($user, 'sanctum')->post('/api/photos/upload', nameUploadPayload($child))
            ->assertCreated()->assertJsonPath('data.name_encrypted', null)->assertJsonPath('data.iv_name', null);

        expect(Schema::hasColumn('photos', 'name'))->toBeFalse()
            ->and(Schema::hasColumn('photos', 'filename'))->toBeFalse();
    });
});
