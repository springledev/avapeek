<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;

describe('GET /api/quota', function () {
    it('returns system-wide storage usage', function () {
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
                'file' => UploadedFile::fake()->create('photo.heic', 1),
                'iv' => bin2hex(random_bytes(16)),
                'thumbnail' => UploadedFile::fake()->create('thumb.webp', 1),
                'iv_thumbnail' => bin2hex(random_bytes(16)),
                'original_mime' => 'image/heic',
                'size_bytes' => 1024,
                'children' => $childrenData,
            ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/quota');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['used_bytes', 'limit_bytes', 'percentage'],
            ])
            ->assertJsonPath('data.used_bytes', 2048); // 1KB file + 1KB thumbnail
    });

    it('returns zero usage when no photos exist', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/quota');

        $response->assertStatus(200)
            ->assertJsonPath('data.used_bytes', 0);
    });

    it('requires authentication', function () {
        $this->getJson('/api/quota')->assertStatus(401);
    });
});
