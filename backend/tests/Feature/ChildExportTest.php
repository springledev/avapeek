<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

function exportFamily(string $role = 'member'): array
{
    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => $role]);

    return [$user, $child];
}

function exportPhoto(User $uploader, array $children, array $attributes = []): Photo
{
    $photo = Photo::factory()->create(array_merge(['uploaded_by' => $uploader->id], $attributes));
    foreach ($children as $child) {
        $photo->children()->attach($child->id, [
            'encrypted_pek' => 'pek-for-'.$child->id,
            'iv_pek' => str_pad((string) $child->id, 32, 'a'),
        ]);
    }

    return $photo;
}

describe('GET /api/children/{child}/export/photos', function () {
    it('lists only photos linked to the requested child', function () {
        [$user, $child] = exportFamily();
        $other = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $other->id, 'user_id' => $user->id, 'role' => 'owner']);

        $mine = exportPhoto($user, [$child]);
        $shared = exportPhoto($user, [$child, $other]);
        exportPhoto($user, [$other]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/photos")
            ->assertOk();

        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine->id, $shared->id])
            ->and($response->json('meta.total'))->toBe(2)
            ->and($response->json('meta.next_after_id'))->toBeNull();
    });

    it('returns only the requested child pivot and tags, ciphertext and a 15 minute presigned url', function () {
        [$user, $child] = exportFamily();
        $other = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $other->id, 'user_id' => $user->id, 'role' => 'owner']);
        $photo = exportPhoto($user, [$child, $other], ['name_encrypted' => 'cipher-name', 'iv_name' => str_repeat('b', 32)]);
        $mineTag = Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id]);
        $otherTag = Tag::factory()->create(['child_id' => $other->id, 'created_by' => $user->id]);
        $photo->tags()->attach([$mineTag->id, $otherTag->id]);

        $row = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/photos")
            ->assertOk()->json('data.0');

        expect($row['encrypted_pek'])->toBe('pek-for-'.$child->id)
            ->and($row['iv_pek'])->toBe(str_pad((string) $child->id, 32, 'a'))
            ->and($row['name_encrypted'])->toBe('cipher-name')
            ->and($row['iv_name'])->toBe(str_repeat('b', 32))
            ->and(collect($row['tags'])->pluck('id')->all())->toBe([$mineTag->id])
            ->and($row['tags'][0])->toHaveKeys(['id', 'child_id', 'name_encrypted', 'iv_name'])
            ->and($row['tags'][0])->not->toHaveKey('name')
            ->and($row)->not->toHaveKey('children')
            ->and($row)->not->toHaveKeys(['original_path', 'thumbnail_path'])
            ->and($row['presigned_url'])->toBeString()->not->toBeEmpty();
    });

    it('signs the original url for at least 15 minutes', function () {
        [$user, $child] = exportFamily();
        exportPhoto($user, [$child]);

        $this->freezeTime();
        $expiries = [];
        Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, $expiration) use (&$expiries) {
            $expiries[] = $expiration;

            return 'https://storage.example.test/'.$path;
        });

        $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos")->assertOk();

        expect($expiries)->toHaveCount(1)
            ->and($expiries[0]->gte(now()->addMinutes(15)))->toBeTrue();
    });

    it('lets any member export but rejects non-members and unknown children', function () {
        [$owner, $child] = exportFamily('owner');
        exportPhoto($owner, [$child]);
        $member = User::factory()->create();
        ChildUser::create(['child_id' => $child->id, 'user_id' => $member->id, 'role' => 'member']);
        $outsider = User::factory()->create();

        $this->actingAs($member, 'sanctum')->getJson("/api/children/{$child->id}/export/photos")->assertOk();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/children/{$child->id}/export/photos")->assertForbidden();
        $this->actingAs($owner, 'sanctum')->getJson('/api/children/999999/export/photos')->assertNotFound();
    });

    it('requires authentication', function () {
        [, $child] = exportFamily();

        $this->getJson("/api/children/{$child->id}/export/photos")->assertUnauthorized();
        $this->getJson("/api/children/{$child->id}/export/comments")->assertUnauthorized();
    });

    it('isolates families', function () {
        [$userA, $childA] = exportFamily();
        [$userB, $childB] = exportFamily();
        exportPhoto($userB, [$childB]);

        $this->actingAs($userA, 'sanctum')->getJson("/api/children/{$childB->id}/export/photos")->assertForbidden();
        $this->actingAs($userA, 'sanctum')->getJson("/api/children/{$childA->id}/export/photos")
            ->assertOk()->assertJsonCount(0, 'data');
    });

    it('paginates by keyset and stays stable when a photo is inserted mid-listing', function () {
        [$user, $child] = exportFamily();
        $first = collect(range(1, 3))->map(fn () => exportPhoto($user, [$child]));

        $page1 = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/photos?limit=2")
            ->assertOk();
        expect(collect($page1->json('data'))->pluck('id')->all())->toBe([$first[0]->id, $first[1]->id])
            ->and($page1->json('meta.next_after_id'))->toBe($first[1]->id)
            ->and($page1->json('meta.total'))->toBe(3);

        $inserted = exportPhoto($user, [$child], ['taken_at' => now()->subYears(5)]);

        $page2 = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/photos?limit=2&after_id=".$first[1]->id)
            ->assertOk();
        expect(collect($page2->json('data'))->pluck('id')->all())->toBe([$first[2]->id, $inserted->id])
            ->and($page2->json('meta'))->not->toHaveKey('total')
            ->and($page2->json('meta.next_after_id'))->toBeNull();
    });

    it('validates paging parameters', function () {
        [$user, $child] = exportFamily();

        $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos?limit=101")->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos?limit=0")->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos?after_id=-1")->assertUnprocessable();
    });

    it('does not run queries per photo', function () {
        [$user, $child] = exportFamily();
        $count = function () use ($user, $child): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos")->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 2) as $i) {
            $photo = exportPhoto($user, [$child]);
            $photo->tags()->attach(Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id])->id);
        }
        $small = $count();

        foreach (range(1, 10) as $i) {
            $photo = exportPhoto($user, [$child]);
            $photo->tags()->attach(Tag::factory()->create(['child_id' => $child->id, 'created_by' => $user->id])->id);
        }

        expect($count())->toBe($small);
    });

    it('enforces the export rate limit independently of the api limit', function () {
        [$user, $child] = exportFamily();

        foreach (range(1, 30) as $i) {
            $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos")->assertOk();
        }

        $this->actingAs($user, 'sanctum')->getJson("/api/children/{$child->id}/export/photos")->assertStatus(429);
        $this->actingAs($user, 'sanctum')->getJson('/api/children')->assertOk();
    });
});

describe('GET /api/children/{child}/export/comments', function () {
    it('returns ciphertext comments only for photos in the requested child', function () {
        [$user, $child] = exportFamily();
        $other = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $other->id, 'user_id' => $user->id, 'role' => 'owner']);
        $inChild = exportPhoto($user, [$child]);
        $elsewhere = exportPhoto($user, [$other]);
        $mine = Comment::factory()->create(['photo_id' => $inChild->id, 'user_id' => $user->id]);
        Comment::factory()->create(['photo_id' => $elsewhere->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/comments")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        $row = $response->json('data.0');
        expect($row['id'])->toBe($mine->id)
            ->and($row['photo_id'])->toBe($inChild->id)
            ->and($row['author_name'])->toBe($user->display_name)
            ->and($row['body_encrypted'])->toBe($mine->body_encrypted)
            ->and($row['iv_body'])->toBe($mine->iv_body)
            ->and($row)->not->toHaveKey('body');
    });

    it('paginates by keyset and rejects non-members', function () {
        [$user, $child] = exportFamily();
        $photo = exportPhoto($user, [$child]);
        $comments = Comment::factory()->count(3)->create(['photo_id' => $photo->id, 'user_id' => $user->id]);

        $page1 = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/comments?limit=2")->assertOk();
        expect($page1->json('meta.next_after_id'))->toBe($comments[1]->id);

        $page2 = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/export/comments?limit=2&after_id=".$comments[1]->id)->assertOk();
        expect(collect($page2->json('data'))->pluck('id')->all())->toBe([$comments[2]->id])
            ->and($page2->json('meta.next_after_id'))->toBeNull();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/children/{$child->id}/export/comments")->assertForbidden();
    });
});
