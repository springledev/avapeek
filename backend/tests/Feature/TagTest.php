<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function tagFamily(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

    return [$user, $child];
}

function joinChild(Child $child, string $role = 'member', bool $canUpload = true): User
{
    $user = User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role, 'can_upload' => $canUpload]);

    return $user;
}

function photoIn(User $uploader, Child ...$children): Photo
{
    $photo = Photo::factory()->create(['uploaded_by' => $uploader->id]);
    foreach ($children as $child) {
        $photo->children()->attach($child->id, ['encrypted_pek' => 't', 'iv_pek' => 'test_test_test_test!']);
    }

    return $photo;
}

function tagEnvelope(array $overrides = []): array
{
    return array_merge([
        'name_encrypted' => base64_encode(random_bytes(24)),
        'iv_name' => bin2hex(random_bytes(16)),
    ], $overrides);
}

describe('tag storage', function () {
    it('compiles the locked tag recheck for PostgreSQL as a row select with for share and no aggregate', function () {
        $query = Tag::lockedLinkableIdsQuery([1, 2], [3])->getQuery();
        $query->grammar = new PostgresGrammar($query->getConnection());

        $sql = strtolower($query->toSql());

        expect($sql)->toContain('for share')->and($sql)->not->toContain('count(');
    });

    it('has no plaintext name column', function () {
        expect(Schema::hasColumn('tags', 'name'))->toBeFalse()
            ->and(Schema::hasColumns('tags', ['child_id', 'created_by', 'name_encrypted', 'iv_name']))->toBeTrue();
    });
});

describe('GET /api/children/{child}/tags', function () {
    it('lists ciphertext tags with photo counts for that child only', function () {
        [$user, $child] = tagFamily();
        $otherChild = Child::factory()->create();
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $user->id, 'role' => 'owner']);

        $used = Tag::factory()->create(['child_id' => $child->id]);
        $unused = Tag::factory()->create(['child_id' => $child->id]);
        Tag::factory()->create(['child_id' => $otherChild->id]);

        $shared = photoIn($user, $child, $otherChild);
        $shared->tags()->attach($used->id);
        $elsewhere = photoIn($user, $otherChild);
        $elsewhere->tags()->attach($used->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/tags")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $used->id)
            ->assertJsonPath('data.0.name_encrypted', $used->name_encrypted)
            ->assertJsonPath('data.0.iv_name', $used->iv_name)
            ->assertJsonPath('data.0.photo_count', 1)
            ->assertJsonPath('data.1.id', $unused->id)
            ->assertJsonPath('data.1.photo_count', 0);

        expect($response->json('data.0'))->not->toHaveKey('name');
    });

    it('returns an empty list when there are no tags', function () {
        [$user, $child] = tagFamily();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/tags")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('requires authentication', function () {
        [, $child] = tagFamily();
        $this->getJson("/api/children/{$child->id}/tags")->assertStatus(401);
    });

    it('does not list another child tags to an outsider', function () {
        [, $child] = tagFamily();
        [$outsider] = tagFamily();
        Tag::factory()->create(['child_id' => $child->id]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/children/{$child->id}/tags")
            ->assertStatus(403);
    });

    it('no longer exposes the global tag list', function () {
        [$user] = tagFamily();

        $this->actingAs($user, 'sanctum')->getJson('/api/tags')->assertStatus(404);
    });
});

describe('POST /api/children/{child}/tags', function () {
    it('stores only the ciphertext envelope and never returns a name', function () {
        [$user, $child] = tagFamily();
        $envelope = tagEnvelope();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", $envelope)
            ->assertCreated()
            ->assertJsonPath('data.name_encrypted', $envelope['name_encrypted'])
            ->assertJsonPath('data.iv_name', $envelope['iv_name'])
            ->assertJsonPath('data.photo_count', 0);

        expect($response->json('data'))->not->toHaveKey('name');
        $this->assertDatabaseHas('tags', [
            'child_id' => $child->id,
            'created_by' => $user->id,
            'name_encrypted' => $envelope['name_encrypted'],
            'iv_name' => $envelope['iv_name'],
        ]);
    });

    it('lets any member create a tag', function () {
        [, $child] = tagFamily();
        $member = joinChild($child);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", tagEnvelope())
            ->assertCreated();
    });

    it('requires authentication', function () {
        [, $child] = tagFamily();
        $this->postJson("/api/children/{$child->id}/tags", tagEnvelope())->assertStatus(401);
    });

    it('does not let an outsider create a tag for another child', function () {
        [, $child] = tagFamily();
        [$outsider] = tagFamily();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", tagEnvelope())
            ->assertStatus(403);
        expect(Tag::count())->toBe(0);
    });

    it('validates the ciphertext envelope', function (array $overrides) {
        [$user, $child] = tagFamily();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", tagEnvelope($overrides))
            ->assertStatus(422);
    })->with([
        'missing ciphertext' => [['name_encrypted' => null]],
        'not base64' => [['name_encrypted' => 'not base64!!']],
        'too long' => [['name_encrypted' => str_repeat('A', 513)]],
        'short iv' => [['iv_name' => 'abcd']],
        'non hex iv' => [['iv_name' => str_repeat('z', 32)]],
    ]);

    it('rejects the 201st tag of a child with a clear message', function () {
        [$user, $child] = tagFamily();
        Tag::factory()->count(Tag::MAX_PER_CHILD)->create(['child_id' => $child->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", tagEnvelope())
            ->assertStatus(422)
            ->assertJsonPath('message', 'This child has reached the maximum of 200 tags.');

        expect(Tag::where('child_id', $child->id)->count())->toBe(Tag::MAX_PER_CHILD);
    });

    it('counts the cap per child', function () {
        [$user, $child] = tagFamily();
        $other = Child::factory()->create();
        ChildUser::create(['child_id' => $other->id, 'user_id' => $user->id, 'role' => 'owner']);
        Tag::factory()->count(Tag::MAX_PER_CHILD)->create(['child_id' => $child->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$other->id}/tags", tagEnvelope())
            ->assertCreated();
    });

    it('is throttled per user', function () {
        [$user, $child] = tagFamily();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/children/{$child->id}/tags", tagEnvelope())
                ->assertCreated();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/children/{$child->id}/tags", tagEnvelope())
            ->assertStatus(429);
    });
});

describe('DELETE /api/tags/{tag}', function () {
    it('deletes a tag and removes all photo associations', function () {
        [$user, $child] = tagFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id]);
        $photo = photoIn($user, $child);
        $photo->tags()->attach($tag->id);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(204);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('photo_tags', ['tag_id' => $tag->id]);
    });

    it('requires authentication', function () {
        $tag = Tag::factory()->create();
        $this->deleteJson("/api/tags/{$tag->id}")->assertStatus(401);
    });

    it('returns 404 for non-existent tags', function () {
        [$user] = tagFamily();
        $this->actingAs($user, 'sanctum')->deleteJson('/api/tags/99999')->assertStatus(404);
    });

    it('does not let an outsider delete a tag, and answers like an unknown tag', function () {
        [$alice, $child] = tagFamily();
        [$bob] = tagFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $alice->id]);

        $this->actingAs($bob, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(404);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    });

    it('does not let a member without upload permission delete a tag', function () {
        [$alice, $child] = tagFamily();
        $member = joinChild($child, 'member', canUpload: false);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $alice->id]);

        $this->actingAs($member, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(403);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    });

    it('lets the creator and an owner or admin of the child delete a tag', function () {
        [$alice, $child] = tagFamily();
        $admin = joinChild($child, 'admin');
        $member = joinChild($child);
        $memberTag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $member->id]);
        $ownTag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $member->id]);
        $adminDeleted = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $member->id]);

        $this->actingAs($alice, 'sanctum')->deleteJson("/api/tags/{$memberTag->id}")->assertStatus(204);
        $this->actingAs($member, 'sanctum')->deleteJson("/api/tags/{$ownTag->id}")->assertStatus(204);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/tags/{$adminDeleted->id}")->assertStatus(204);
    });

    it('lets an owner delete a tag whose creator has left', function () {
        [$alice, $child] = tagFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => null]);

        $this->actingAs($alice, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(204);
    });

    it('does not let a creator who left the child delete their old tag', function () {
        [, $child] = tagFamily();
        $leaver = User::factory()->create();
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $leaver->id]);

        $this->actingAs($leaver, 'sanctum')->deleteJson("/api/tags/{$tag->id}")->assertStatus(404);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    });
});

describe('POST /api/photos/{photo}/tags (attach)', function () {
    it('attaches tags to a photo', function () {
        [$user, $child] = tagFamily();
        $photo = photoIn($user, $child);
        $tag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tag->id]])
            ->assertOk()
            ->assertJsonPath('data.photo_id', $photo->id)
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.tags.0.child_id', $child->id)
            ->assertJsonPath('data.tags.0.name_encrypted', $tag->name_encrypted);

        expect($response->json('data.tags.0'))->not->toHaveKey('name');
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag->id]);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $this->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [1]])->assertStatus(401);
    });

    it('rejects a tag from another family', function () {
        [$alice, $child] = tagFamily();
        [, $bobChild] = tagFamily();
        $photo = photoIn($alice, $child);
        $foreign = Tag::factory()->create(['child_id' => $bobChild->id]);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$foreign->id]])
            ->assertStatus(422);
        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $foreign->id]);
    });

    it('answers unknown and foreign tag ids identically', function () {
        [$alice, $child] = tagFamily();
        [, $bobChild] = tagFamily();
        $photo = photoIn($alice, $child);
        $foreign = Tag::factory()->create(['child_id' => $bobChild->id]);

        $unknown = $this->actingAs($alice, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [99999]]);
        $foreignResponse = $this->actingAs($alice, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$foreign->id]]);

        expect($unknown->status())->toBe(422)
            ->and($foreignResponse->status())->toBe(422)
            ->and($foreignResponse->json('message'))->toBe($unknown->json('message'));
    });

    it('rejects a tag of a child the photo is not linked to, even when the user is a member', function () {
        [$user, $child] = tagFamily();
        $otherChild = Child::factory()->create();
        ChildUser::create(['child_id' => $otherChild->id, 'user_id' => $user->id, 'role' => 'owner']);
        $photo = photoIn($user, $child);
        $unlinked = Tag::factory()->create(['child_id' => $otherChild->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$unlinked->id]])
            ->assertStatus(422);
    });

    it('is all-or-nothing when one id is invalid', function () {
        [$user, $child] = tagFamily();
        $photo = photoIn($user, $child);
        $good = Tag::factory()->create(['child_id' => $child->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$good->id, 99999]])
            ->assertStatus(422);
        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id]);
    });

    it('lets a member of one child attach that child tag to a photo shared with another child', function () {
        [$alice, $childA] = tagFamily();
        [$bob, $childB] = tagFamily();
        $photo = photoIn($alice, $childA, $childB);
        $tagA = Tag::factory()->create(['child_id' => $childA->id]);
        $tagB = Tag::factory()->create(['child_id' => $childB->id]);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tagA->id]])
            ->assertOk();
        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tagB->id]])
            ->assertStatus(422);
        $this->actingAs($bob, 'sanctum')
            ->postJson("/api/photos/{$photo->id}/tags", ['tag_ids' => [$tagB->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tagB->id);
    });
});

describe('DELETE /api/photos/{photo}/tags/{tag} (detach)', function () {
    it('detaches a tag from a photo', function () {
        [$user, $child] = tagFamily();
        $photo = photoIn($user, $child);
        $tag = Tag::factory()->create(['child_id' => $child->id]);
        $photo->tags()->attach($tag->id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag->id]);
    });

    it('requires authentication', function () {
        $photo = Photo::factory()->create();
        $tag = Tag::factory()->create();
        $this->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")->assertStatus(401);
    });

    it('leaves a tag of an inaccessible child untouched', function () {
        [$alice, $childA] = tagFamily();
        [$bob, $childB] = tagFamily();
        $shared = photoIn($alice, $childA, $childB);
        $bobTag = Tag::factory()->create(['child_id' => $childB->id, 'created_by' => $bob->id]);
        $shared->tags()->attach($bobTag->id);
        $bobOnly = photoIn($bob, $childB);
        $bobOnly->tags()->attach($bobTag->id);

        $this->actingAs($alice, 'sanctum')
            ->deleteJson("/api/photos/{$shared->id}/tags/{$bobTag->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('photo_tags', ['photo_id' => $shared->id, 'tag_id' => $bobTag->id]);
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $bobOnly->id, 'tag_id' => $bobTag->id]);
    });

    it('denies a non-member of the photo', function () {
        [$alice, $child] = tagFamily();
        [$bob] = tagFamily();
        $photo = photoIn($alice, $child);
        $tag = Tag::factory()->create(['child_id' => $child->id]);
        $photo->tags()->attach($tag->id);

        $this->actingAs($bob, 'sanctum')
            ->deleteJson("/api/photos/{$photo->id}/tags/{$tag->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('photo_tags', ['photo_id' => $photo->id, 'tag_id' => $tag->id]);
    });
});

describe('photo payloads only carry the viewer tags', function () {
    it('returns only tags of the requesting user children for a photo shared across children', function () {
        Storage::fake('s3');
        [$alice, $childA] = tagFamily();
        [$bob, $childB] = tagFamily();
        $photo = photoIn($alice, $childA, $childB);
        $tagA = Tag::factory()->create(['child_id' => $childA->id]);
        $tagB = Tag::factory()->create(['child_id' => $childB->id]);
        $photo->tags()->attach([$tagA->id, $tagB->id]);

        $this->actingAs($alice, 'sanctum')->getJson("/api/photos/{$photo->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tagA->id)
            ->assertJsonPath('data.tags.0.iv_name', $tagA->iv_name);

        $this->actingAs($bob, 'sanctum')->getJson('/api/photos')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.tags')
            ->assertJsonPath('data.0.tags.0.id', $tagB->id);

        $this->actingAs($bob, 'sanctum')->getJson('/api/recents')
            ->assertOk()
            ->assertJsonPath('data.0.tags.0.id', $tagB->id);
    });
});

describe('viewer tag isolation across requests', function () {
    it('never filters one user with another user children through a reused controller', function () {
        Storage::fake('s3');
        [$alice, $childA] = tagFamily();
        [$bob, $childB] = tagFamily();
        $photo = photoIn($alice, $childA, $childB);
        $tagA = Tag::factory()->create(['child_id' => $childA->id]);
        $tagB = Tag::factory()->create(['child_id' => $childB->id]);
        $photo->tags()->attach([$tagA->id, $tagB->id]);

        $tagIdsOf = fn ($response, string $path) => collect($response->json($path))->pluck('id')->all();

        foreach ([[$alice, $tagA], [$bob, $tagB], [$alice, $tagA], [$bob, $tagB]] as [$viewer, $own]) {
            $this->actingAs($viewer, 'sanctum');
            expect($tagIdsOf($this->getJson("/api/photos/{$photo->id}")->assertOk(), 'data.tags'))->toBe([$own->id])
                ->and($tagIdsOf($this->getJson('/api/photos')->assertOk(), 'data.0.tags'))->toBe([$own->id])
                ->and($tagIdsOf($this->getJson('/api/recents')->assertOk(), 'data.0.tags'))->toBe([$own->id]);
        }
    });
});

describe('tag_ids on upload', function () {
    function tagUploadPayload(Child $child, array $extra = []): array
    {
        return array_merge([
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
        ], $extra);
    }

    it('attaches the given tags inside the upload', function () {
        Storage::fake('s3');
        [$alice, $child] = tagFamily();
        $tag = Tag::factory()->create(['child_id' => $child->id]);

        $response = $this->actingAs($alice, 'sanctum')
            ->post('/api/photos/upload', tagUploadPayload($child, ['tag_ids' => [$tag->id]]))
            ->assertCreated()
            ->assertJsonPath('data.tags.0.id', $tag->id);

        $this->assertDatabaseHas('photo_tags', ['photo_id' => $response->json('data.id'), 'tag_id' => $tag->id]);
    });

    it('rejects a foreign tag and stores nothing', function () {
        Storage::fake('s3');
        [$alice, $child] = tagFamily();
        [, $bobChild] = tagFamily();
        $foreign = Tag::factory()->create(['child_id' => $bobChild->id]);

        $this->actingAs($alice, 'sanctum')
            ->post('/api/photos/upload', tagUploadPayload($child, ['tag_ids' => [$foreign->id]]), ['Accept' => 'application/json'])
            ->assertStatus(422);

        expect(Photo::count())->toBe(0)
            ->and(Storage::disk('s3')->allFiles())->toBe([]);
    });

    it('rejects a tag of a child the photo is not being linked to', function () {
        Storage::fake('s3');
        [$alice, $child] = tagFamily();
        $other = Child::factory()->create();
        ChildUser::create(['child_id' => $other->id, 'user_id' => $alice->id, 'role' => 'owner']);
        $unlinked = Tag::factory()->create(['child_id' => $other->id]);

        $this->actingAs($alice, 'sanctum')
            ->post('/api/photos/upload', tagUploadPayload($child, ['tag_ids' => [$unlinked->id]]), ['Accept' => 'application/json'])
            ->assertStatus(422);

        expect(Photo::count())->toBe(0);
    });

    it('rejects the old plaintext tags field', function () {
        Storage::fake('s3');
        [$alice, $child] = tagFamily();

        $this->actingAs($alice, 'sanctum')
            ->post('/api/photos/upload', tagUploadPayload($child, ['tags' => ['Spain 2026']]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags']);

        expect(Photo::count())->toBe(0)->and(Tag::count())->toBe(0);
    });
});

describe('tag_ids feed filter', function () {
    it('ignores an array tag_ids value instead of failing', function () {
        Storage::fake('s3');
        [$alice, $child] = tagFamily();
        photoIn($alice, $child);

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/photos?tag_ids[]=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    });

    it('respects access and does not leak photos through another child tag', function () {
        [$alice, $child] = tagFamily();
        [$bob, $bobChild] = tagFamily();
        $bobTag = Tag::factory()->create(['child_id' => $bobChild->id]);
        $bobPhoto = photoIn($bob, $bobChild);
        $bobPhoto->tags()->attach($bobTag->id);
        photoIn($alice, $child);

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/photos?tag_ids='.$bobTag->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    });
});
