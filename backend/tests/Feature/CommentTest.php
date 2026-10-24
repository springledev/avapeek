<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * @return array{User, Child, Photo}
 */
function commentFixture(string $ownerRole = 'owner'): array
{
    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $ownerRole]);

    $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
    $photo->children()->attach($child->id, [
        'encrypted_pek' => bin2hex(random_bytes(32)),
        'iv_pek' => bin2hex(random_bytes(16)),
    ]);

    return [$user, $child, $photo];
}

function ciphertextPayload(): array
{
    return [
        'body_encrypted' => base64_encode(random_bytes(48)),
        'iv_body' => bin2hex(random_bytes(16)),
    ];
}

describe('POST /api/photos/{photo}/comments', function () {
    it('requires authentication', function () {
        $photo = Photo::factory()->create();

        $this->postJson("/api/photos/{$photo->id}/comments", ciphertextPayload())
            ->assertStatus(401);
    });

    it('returns 403 for non-member who shares no child with the photo', function () {
        [, , $photo] = commentFixture();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", ciphertextPayload())
            ->assertStatus(403);
    });

    it('creates a comment storing ciphertext exactly as sent', function () {
        [$user, , $photo] = commentFixture();
        $payload = ciphertextPayload();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'user_id', 'author_name', 'body_encrypted', 'iv_body', 'created_at'],
            ])
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.author_name', $user->display_name)
            ->assertJsonPath('data.body_encrypted', $payload['body_encrypted'])
            ->assertJsonPath('data.iv_body', $payload['iv_body']);

        $this->assertDatabaseHas('comments', [
            'id' => $response->json('data.id'),
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'body_encrypted' => $payload['body_encrypted'],
            'iv_body' => $payload['iv_body'],
        ]);
    });

    it('validates required fields', function () {
        [$user, , $photo] = commentFixture();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", [])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", ['body_encrypted' => 'abc'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", ['iv_body' => bin2hex(random_bytes(16))])
            ->assertStatus(422);
    });

    it('validates iv_body is exactly 32 characters', function () {
        [$user, , $photo] = commentFixture();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", [
                'body_encrypted' => base64_encode(random_bytes(48)),
                'iv_body' => 'too-short',
            ])
            ->assertStatus(422);
    });

    it('rejects body_encrypted above the 100000 character cap', function () {
        [$user, , $photo] = commentFixture();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", [
                'body_encrypted' => str_repeat('A', 100001),
                'iv_body' => bin2hex(random_bytes(16)),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body_encrypted');

        $this->assertDatabaseMissing('comments', ['photo_id' => $photo->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", [
                'body_encrypted' => str_repeat('A', 100000),
                'iv_body' => bin2hex(random_bytes(16)),
            ])
            ->assertStatus(201);
    });
});

describe('GET /api/photos/{photo}/comments', function () {
    it('requires authentication', function () {
        $photo = Photo::factory()->create();

        $this->getJson("/api/photos/{$photo->id}/comments")->assertStatus(401);
    });

    it('returns 403 for non-member', function () {
        [, , $photo] = commentFixture();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/comments")
            ->assertStatus(403);
    });

    it('returns paginated list with 50 per page ordered oldest first', function () {
        [$user, , $photo] = commentFixture();

        Comment::factory()->count(51)->create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/comments");

        $response->assertStatus(200)
            ->assertJsonCount(50, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'author_name', 'body_encrypted', 'iv_body', 'created_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 51);

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids->all())->toBe($ids->sort()->values()->all());

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/comments?page=2")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    });

    it('caps the page size at 50 regardless of the per_page query parameter', function () {
        [$user, , $photo] = commentFixture();

        Comment::factory()->count(60)->create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/comments?per_page=1000");

        $response->assertStatus(200)
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 60);
    });

    it('returns only ciphertext, never plaintext', function () {
        [$user, , $photo] = commentFixture();
        $plaintext = 'Happy birthday Emma!';
        $payload = ciphertextPayload();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", $payload)
            ->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/comments");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.body_encrypted', $payload['body_encrypted']);

        expect($response->getContent())->not->toContain($plaintext);
    });
});

describe('DELETE /api/photos/{photo}/comments/{comment}', function () {
    it('requires authentication', function () {
        [, , $photo] = commentFixture();
        $comment = Comment::factory()->create(['photo_id' => $photo->id]);

        $this->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(401);
    });

    it('returns 403 for non-member', function () {
        [, , $photo] = commentFixture();
        $comment = Comment::factory()->create(['photo_id' => $photo->id]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(403);
    });

    it('returns 404 when the comment belongs to a different accessible photo', function () {
        [$user, $child, $photoA] = commentFixture();

        $photoB = Photo::factory()->create(['uploaded_by' => $user->id]);
        $photoB->children()->attach($child->id, [
            'encrypted_pek' => bin2hex(random_bytes(32)),
            'iv_pek' => bin2hex(random_bytes(16)),
        ]);

        $comment = Comment::factory()->create([
            'photo_id' => $photoA->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photoB->id}/comments/{$comment->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    });

    it('returns 403 for non-author non-admin member', function () {
        [$owner, $child, $photo] = commentFixture();
        $plainMember = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $plainMember->id, 'role' => 'member']);
        $author = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $author->id, 'role' => 'member']);

        $comment = Comment::factory()->create([
            'photo_id' => $photo->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($plainMember, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    });

    it('lets the author delete their own comment', function () {
        [$user, , $photo] = commentFixture();
        $comment = Comment::factory()->create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    });

    it('lets an admin delete any comment', function () {
        [$admin, $child, $photo] = commentFixture('admin');
        $author = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $author->id, 'role' => 'member']);

        $comment = Comment::factory()->create([
            'photo_id' => $photo->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/comments/{$comment->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    });

    it('cascade deletes comments when the photo is deleted', function () {
        [$user, , $photo] = commentFixture();
        $comment = Comment::factory()->create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
        ]);

        $photo->delete();

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertDatabaseMissing('comments', ['photo_id' => $photo->id]);
    });
});

describe('encryption contract', function () {
    it('never exposes decryptable text in API responses', function () {
        [$user, , $photo] = commentFixture();
        $plaintext = 'Secret family message 123';
        $ciphertext = base64_encode($plaintext);

        // The server is a dumb store: it never decrypts, so we hand it the base64 of a
        // known marker and assert the API echoes back exactly that stored ciphertext
        // and nothing that resembles the raw marker string.
        $storeResponse = $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/comments", [
                'body_encrypted' => $ciphertext,
                'iv_body' => bin2hex(random_bytes(16)),
            ]);

        $storeResponse->assertStatus(201)
            ->assertJsonPath('data.body_encrypted', $ciphertext);

        expect($storeResponse->getContent())->not->toContain($plaintext);

        $this->assertDatabaseHas('comments', [
            'id' => $storeResponse->json('data.id'),
            'body_encrypted' => $ciphertext,
        ]);

        $indexResponse = $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}/comments");

        $indexResponse->assertStatus(200)
            ->assertJsonPath('data.0.body_encrypted', $ciphertext);

        expect($indexResponse->getContent())->not->toContain($plaintext);

        $detailResponse = $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}");

        $detailResponse->assertStatus(200);
        expect($detailResponse->getContent())->not->toContain($plaintext);

        $feedResponse = $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos');

        $feedResponse->assertStatus(200);
        expect($feedResponse->getContent())->not->toContain($plaintext);
    });

    it('exposes comment_count on the photo feed', function () {
        [$user, , $photo] = commentFixture();
        Comment::factory()->count(3)->create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/photos')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $photo->id)
            ->assertJsonPath('data.0.comment_count', 3);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/photos/{$photo->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.comment_count', 3);
    });
});
