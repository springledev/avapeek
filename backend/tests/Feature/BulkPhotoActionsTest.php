<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\StorageUsage;
use App\Models\Tag;
use App\Models\User;
use App\Services\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function bulkMember(Child $child, string $role = 'member', ?User $user = null): User
{
    $user ??= User::factory()->create();
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function bulkPhoto(Child $child, ?User $uploader = null, array $attributes = []): Photo
{
    $photo = Photo::factory()->create(array_merge(['uploaded_by' => ($uploader ?? User::factory()->create())->id], $attributes));
    bulkLink($photo, $child);

    return $photo;
}

function bulkLink(Photo $photo, Child $child, ?string $pek = null): void
{
    $photo->children()->attach($child->id, [
        'encrypted_pek' => $pek ?? base64_encode(random_bytes(48)),
        'iv_pek' => base64_encode(random_bytes(12)),
    ]);
}

/** @return array{encrypted_pek: string, iv_pek: string} */
function bulkWrap(): array
{
    return ['encrypted_pek' => base64_encode(random_bytes(48)), 'iv_pek' => base64_encode(random_bytes(12))];
}

beforeEach(function () {
    Storage::fake('s3');
});

describe('POST /api/photos/bulk-delete', function () {
    it('requires authentication', function () {
        $this->postJson('/api/photos/bulk-delete', ['photo_ids' => [1]])->assertUnauthorized();
    });

    it('validates ids: empty, 101, duplicates and non integers', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-delete', ['photo_ids' => []])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-delete', ['photo_ids' => range(1, 101)])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-delete', ['photo_ids' => [1, 1]])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-delete', ['photo_ids' => ['x']])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-delete', ['photo_ids' => range(1, 100)])->assertOk();
    });

    it('buckets deleted, detached and forbidden; omits invisible and unknown ids identically', function () {
        $admin = User::factory()->create();
        $childA = Child::factory()->create(['created_by' => $admin->id]);
        $childB = Child::factory()->create();
        bulkMember($childA, 'admin', $admin);
        $outsider = User::factory()->create();
        $foreignChild = Child::factory()->create(['created_by' => $outsider->id]);
        bulkMember($foreignChild, 'owner', $outsider);

        $other = User::factory()->create();
        bulkMember($childA, 'member', $other);

        $deleted = bulkPhoto($childA, $other, ['stored_bytes' => 1000]);
        $detached = bulkPhoto($childA, $other, ['stored_bytes' => 500]);
        bulkLink($detached, $childB);
        $foreign = bulkPhoto($foreignChild, $outsider);

        $memberOnly = Child::factory()->create();
        bulkMember($memberOnly, 'member', $admin);
        $forbidden = bulkPhoto($memberOnly, $outsider);

        StorageUsage::global()->update(['used_bytes' => 5000]);

        $storage = $this->mock(StorageService::class);
        $storage->shouldReceive('deleteMultiple')->once()
            ->with([$deleted->original_path, $deleted->thumbnail_path]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/photos/bulk-delete', ['photo_ids' => [$deleted->id, $detached->id, $forbidden->id, $foreign->id, 999999]])
            ->assertOk()
            ->assertExactJson([
                'deleted' => [$deleted->id],
                'detached' => [$detached->id],
                'forbidden' => [$forbidden->id],
            ]);

        expect($response->json('forbidden'))->not->toContain($foreign->id);
        $this->assertDatabaseMissing('photos', ['id' => $deleted->id]);
        $this->assertDatabaseHas('photos', ['id' => $detached->id]);
        $this->assertDatabaseHas('photos', ['id' => $forbidden->id]);
        $this->assertDatabaseHas('photos', ['id' => $foreign->id]);
        $this->assertDatabaseMissing('child_photo', ['photo_id' => $detached->id, 'child_id' => $childA->id]);
        $this->assertDatabaseHas('child_photo', ['photo_id' => $detached->id, 'child_id' => $childB->id]);
        expect((int) StorageUsage::global()->fresh()->used_bytes)->toBe(4000);
    });

    it('lets a plain member remove photos they uploaded but not others', function () {
        $child = Child::factory()->create();
        $member = bulkMember($child);
        $other = bulkMember($child, 'owner');
        $mine = bulkPhoto($child, $member);
        $theirs = bulkPhoto($child, $other);

        $this->mock(StorageService::class)->shouldReceive('deleteMultiple')->once();

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/photos/bulk-delete', ['photo_ids' => [$mine->id, $theirs->id]])
            ->assertOk()
            ->assertExactJson(['deleted' => [$mine->id], 'detached' => [], 'forbidden' => [$theirs->id]]);
    });

    it('keeps a committed removal and its response when blob deletion fails', function () {
        $child = Child::factory()->create();
        $owner = bulkMember($child, 'owner');
        $photo = bulkPhoto($child, $owner);

        $this->mock(StorageService::class)->shouldReceive('deleteMultiple')->andThrow(new RuntimeException('s3 down'));

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/photos/bulk-delete', ['photo_ids' => [$photo->id]])
            ->assertOk()
            ->assertJson(['deleted' => [$photo->id]]);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
    });

    it('does not touch blobs or quota for detached photos', function () {
        $childA = Child::factory()->create();
        $childB = Child::factory()->create();
        $owner = bulkMember($childA, 'owner');
        $photo = bulkPhoto($childA, $owner, ['stored_bytes' => 700]);
        bulkLink($photo, $childB);
        StorageUsage::global()->update(['used_bytes' => 700]);

        $this->mock(StorageService::class)->shouldNotReceive('deleteMultiple');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/photos/bulk-delete', ['photo_ids' => [$photo->id]])
            ->assertOk()
            ->assertJson(['detached' => [$photo->id]]);

        expect((int) StorageUsage::global()->fresh()->used_bytes)->toBe(700);
    });
});

describe('POST /api/photos/bulk-tags', function () {
    function bulkTag(Child $child): Tag
    {
        return Tag::factory()->create(['child_id' => $child->id]);
    }

    it('validates the assignments shape', function () {
        $user = User::factory()->create();
        $post = fn (array $body) => $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-tags', $body);

        $post(['assignments' => []])->assertUnprocessable();
        $post(['assignments' => array_map(fn ($i) => ['photo_id' => $i, 'tag_ids' => [1]], range(1, 101))])->assertUnprocessable();
        $post(['assignments' => [['photo_id' => 1, 'tag_ids' => []]]])->assertUnprocessable();
        $post(['assignments' => [['photo_id' => 1, 'tag_ids' => range(1, 21)]]])->assertUnprocessable();
        $post(['assignments' => [['photo_id' => 1, 'tag_ids' => [3, 3]]]])->assertUnprocessable();
        $post(['assignments' => [['photo_id' => 1, 'tag_ids' => [1]], ['photo_id' => 1, 'tag_ids' => [1]]]])->assertUnprocessable();
        $post(['assignments' => [['photo_id' => 1, 'tag_ids' => [1]], ['photo_id' => 2, 'tag_ids' => [1]]]])->assertOk();
    });

    it('attaches per-photo tags idempotently and skips pairs that fail attachTag', function () {
        $childA = Child::factory()->create();
        $childB = Child::factory()->create();
        $user = bulkMember($childA);
        bulkMember($childB, 'member', $user);
        $foreignChild = Child::factory()->create();
        $stranger = bulkMember($foreignChild, 'owner');

        $photoA = bulkPhoto($childA, $user);
        $photoB = bulkPhoto($childB, $user);
        $foreignPhoto = bulkPhoto($foreignChild, $stranger);
        $tagA = bulkTag($childA);
        $tagB = bulkTag($childB);
        $foreignTag = bulkTag($foreignChild);

        $payload = ['assignments' => [
            ['photo_id' => $photoA->id, 'tag_ids' => [$tagA->id]],
            ['photo_id' => $photoB->id, 'tag_ids' => [$tagA->id]], // tag of a child the photo is not linked to
            ['photo_id' => $foreignPhoto->id, 'tag_ids' => [$foreignTag->id]], // invisible
            ['photo_id' => 999999, 'tag_ids' => [$tagA->id]], // unknown
        ]];

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-tags', $payload)
            ->assertOk()
            ->assertExactJson(['updated' => [$photoA->id], 'skipped' => [$photoB->id]]);
        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-tags', $payload)->assertOk();

        expect(DB::table('photo_tags')->where('photo_id', $photoA->id)->count())->toBe(1);
        expect(DB::table('photo_tags')->where('photo_id', $photoB->id)->count())->toBe(0);
        expect(DB::table('photo_tags')->where('photo_id', $foreignPhoto->id)->count())->toBe(0);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-tags', ['assignments' => [
            ['photo_id' => $photoA->id, 'tag_ids' => [$tagA->id, $foreignTag->id]],
            ['photo_id' => $photoB->id, 'tag_ids' => [$tagB->id]],
        ]])->assertOk()->assertExactJson(['updated' => [$photoB->id], 'skipped' => [$photoA->id]]);
    });

    it('treats an unknown tag id like a failed pair', function () {
        $child = Child::factory()->create();
        $user = bulkMember($child);
        $photo = bulkPhoto($child, $user);

        $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-tags', ['assignments' => [
            ['photo_id' => $photo->id, 'tag_ids' => [999999]],
        ]])->assertOk()->assertExactJson(['updated' => [], 'skipped' => [$photo->id]]);
    });

    it('rolls back every attachment when a failure happens after the writes', function () {
        $child = Child::factory()->create();
        $user = bulkMember($child);
        $photos = [bulkPhoto($child, $user), bulkPhoto($child, $user)];
        $tag = bulkTag($child);

        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'photo_tags') && ++$inserts === 2) {
                throw new RuntimeException('boom after write');
            }
        });

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($user, 'sanctum')->postJson('/api/photos/bulk-tags', ['assignments' => [
            ['photo_id' => $photos[0]->id, 'tag_ids' => [$tag->id]],
            ['photo_id' => $photos[1]->id, 'tag_ids' => [$tag->id]],
        ]]))->toThrow(RuntimeException::class);

        expect(DB::table('photo_tags')->count())->toBe(0);
    });
});

describe('POST /api/photos/share', function () {
    it('shares to a kid the user is in with any role and stores the wrap verbatim', function (string $role) {
        $source = Child::factory()->create();
        $target = Child::factory()->create();
        $user = bulkMember($source, 'member');
        bulkMember($target, $role, $user);
        $photo = bulkPhoto($source, $user);
        $wrap = bulkWrap();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/share', ['child_id' => $target->id, 'photos' => [['photo_id' => $photo->id, ...$wrap]]])
            ->assertOk()
            ->assertExactJson(['shared' => [$photo->id], 'skipped' => []]);

        $this->assertDatabaseHas('child_photo', ['child_id' => $target->id, 'photo_id' => $photo->id, ...$wrap]);
    })->with(['member', 'admin', 'owner']);

    it('is idempotent and never overwrites an existing wrap', function () {
        $source = Child::factory()->create();
        $target = Child::factory()->create();
        $user = bulkMember($source);
        bulkMember($target, 'member', $user);
        $photo = bulkPhoto($source, $user);
        $original = base64_encode(random_bytes(48));
        bulkLink($photo, $target, $original);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/share', ['child_id' => $target->id, 'photos' => [['photo_id' => $photo->id, ...bulkWrap()]]])
            ->assertOk()
            ->assertExactJson(['shared' => [], 'skipped' => [$photo->id]]);

        expect(DB::table('child_photo')->where('child_id', $target->id)->where('photo_id', $photo->id)->value('encrypted_pek'))->toBe($original);
    });

    it('omits photos the user cannot view', function () {
        $source = Child::factory()->create();
        $target = Child::factory()->create();
        $user = bulkMember($source);
        bulkMember($target, 'member', $user);
        $foreignChild = Child::factory()->create();
        $foreign = bulkPhoto($foreignChild);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/share', ['child_id' => $target->id, 'photos' => [
                ['photo_id' => $foreign->id, ...bulkWrap()],
                ['photo_id' => 999999, ...bulkWrap()],
            ]])
            ->assertOk()
            ->assertExactJson(['shared' => [], 'skipped' => []]);

        $this->assertDatabaseMissing('child_photo', ['photo_id' => $foreign->id, 'child_id' => $target->id]);
    });

    it('answers a child the user is not in exactly like an unknown child', function () {
        $source = Child::factory()->create();
        $user = bulkMember($source);
        $photo = bulkPhoto($source, $user);
        $notMine = Child::factory()->create();
        $body = fn (int $childId) => ['child_id' => $childId, 'photos' => [['photo_id' => $photo->id, ...bulkWrap()]]];

        $a = $this->actingAs($user, 'sanctum')->postJson('/api/photos/share', $body($notMine->id));
        $b = $this->actingAs($user, 'sanctum')->postJson('/api/photos/share', $body(999999));

        expect($a->status())->toBe($b->status())->and($a->json('message'))->toBe($b->json('message'));
        $this->assertDatabaseMissing('child_photo', ['photo_id' => $photo->id, 'child_id' => $notMine->id]);
    });

    it('rejects malformed wraps and bad batch sizes', function () {
        $source = Child::factory()->create();
        $user = bulkMember($source);
        $photo = bulkPhoto($source, $user);
        $post = fn (array $photos, ?int $childId = null) => $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/share', ['child_id' => $childId ?? $source->id, 'photos' => $photos]);
        $entry = fn (array $override) => [array_merge(['photo_id' => $photo->id, ...bulkWrap()], $override)];

        $post($entry(['encrypted_pek' => str_repeat('A', 63).'=']))->assertUnprocessable(); // padding where none is valid
        $post($entry(['encrypted_pek' => str_repeat('A', 62)."\n\n"]))->assertUnprocessable(); // whitespace
        $post($entry(['encrypted_pek' => str_repeat('-', 32).str_repeat('_', 32)]))->assertUnprocessable(); // url-safe alphabet
        $post($entry(['encrypted_pek' => base64_encode(random_bytes(47))]))->assertUnprocessable(); // short
        $post($entry(['encrypted_pek' => base64_encode(random_bytes(51))]))->assertUnprocessable(); // long
        $post($entry(['encrypted_pek' => str_repeat('!', 64)]))->assertUnprocessable();
        $post($entry(['iv_pek' => 'AAAAAAAAAAAAAAA=']))->assertUnprocessable(); // padding, decodes to 11 bytes
        $post($entry(['iv_pek' => "AAAAAAAAAAAAAAA\n"]))->assertUnprocessable(); // whitespace
        $post($entry(['iv_pek' => 'AAAAAAAA-_AAAAAA']))->assertUnprocessable(); // url-safe alphabet
        $post($entry(['iv_pek' => str_repeat('A', 20)]))->assertUnprocessable(); // too long
        $post($entry(['iv_pek' => base64_encode(random_bytes(11))]))->assertUnprocessable();
        $post($entry(['iv_pek' => base64_encode(random_bytes(15))]))->assertUnprocessable();
        $post($entry(['iv_pek' => 'AAAAAAAAAAAAAAB=']))->assertUnprocessable(); // non canonical padding bits
        $post([])->assertUnprocessable();
        $post([...$entry([]), ...$entry([])])->assertUnprocessable(); // duplicate photo ids
        $post(array_map(fn ($i) => ['photo_id' => $i, ...bulkWrap()], range(1, 101)))->assertUnprocessable();
        $this->assertDatabaseCount('child_photo', 1);
    });

    it('survives the photo being deleted between the check and the insert', function () {
        $source = Child::factory()->create();
        $target = Child::factory()->create();
        $user = bulkMember($source);
        bulkMember($target, 'member', $user);
        $gone = bulkPhoto($source, $user);
        $stays = bulkPhoto($source, $user);

        $deleted = false;
        DB::beforeExecuting(function (string $sql, array $bindings) use ($gone, &$deleted) {
            if (! $deleted && str_contains($sql, 'child_photo') && str_contains($sql, 'insert') && in_array($gone->id, $bindings, true)) {
                $deleted = true;
                DB::table('photos')->where('id', $gone->id)->delete();
            }
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/photos/share', ['child_id' => $target->id, 'photos' => [
                ['photo_id' => $gone->id, ...bulkWrap()],
                ['photo_id' => $stays->id, ...bulkWrap()],
            ]])
            ->assertOk()
            ->assertExactJson(['shared' => [$stays->id], 'skipped' => []]);

        $this->assertDatabaseMissing('child_photo', ['photo_id' => $gone->id, 'child_id' => $target->id]);
        $this->assertDatabaseHas('child_photo', ['photo_id' => $stays->id, 'child_id' => $target->id]);
    });

    it('requires authentication', function () {
        $this->postJson('/api/photos/share', [])->assertUnauthorized();
    });
});
