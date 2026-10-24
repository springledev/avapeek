<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * Creates a user who owns the given number of children, each with one photo and one extra member.
 *
 * @return array{User, list<Child>}
 */
function childrenFixture(int $childCount): array
{
    $user = User::factory()->create();
    $children = [];

    for ($i = 0; $i < $childCount; $i++) {
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);
        ChildUser::create(['child_id' => $child->id, 'user_id' => User::factory()->create()->id, 'role' => 'viewer']);

        $photo = Photo::factory()->create(['uploaded_by' => $user->id]);
        $photo->children()->attach($child->id, [
            'encrypted_pek' => bin2hex(random_bytes(32)),
            'iv_pek' => bin2hex(random_bytes(16)),
        ]);

        $children[] = $child;
    }

    return [$user, $children];
}

/**
 * @return int number of SQL queries executed while running the callback
 */
function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
}

describe('query count regressions', function () {
    it('keeps GET /api/children query count independent of the number of children', function () {
        [$singleChildUser] = childrenFixture(1);
        [$threeChildUser] = childrenFixture(3);

        $queriesForOneChild = countQueries(fn () => $this->actingAs($singleChildUser, 'sanctum')->getJson('/api/children')->assertOk());
        $queriesForThreeChildren = countQueries(fn () => $this->actingAs($threeChildUser, 'sanctum')->getJson('/api/children')->assertOk());

        expect($queriesForThreeChildren)->toBe($queriesForOneChild);
    });

    it('returns correct photo_count, member_count and role per child on the index', function () {
        [$user, $children] = childrenFixture(3);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/children')
            ->assertOk();

        foreach ($children as $index => $child) {
            $response->assertJsonPath("data.{$index}.id", $child->id)
                ->assertJsonPath("data.{$index}.photo_count", 1)
                ->assertJsonPath("data.{$index}.member_count", 2)
                ->assertJsonPath("data.{$index}.role", 'owner')
                ->assertJsonPath("data.{$index}.relationship", null);
        }
    });

    it('returns the correct photo_count on the child detail endpoint', function () {
        [$user, [$child]] = childrenFixture(1);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}")
            ->assertOk()
            ->assertJsonPath('data.photo_count', 1);
    });

    it('loads comment_count in the photo endpoints without a standalone count query', function (string $method, string $uri, array $payload) {
        [$user, [$child]] = childrenFixture(1);
        $photo = $child->photos()->firstOrFail();
        Comment::factory()->count(2)->create(['photo_id' => $photo->id, 'user_id' => $user->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->actingAs($user, 'sanctum')->json($method, str_replace('{photo}', (string) $photo->id, $uri), $payload)
                ->assertOk()
                ->assertJsonPath('data.comment_count', 2);

            $fallbackCountQueries = collect(DB::getQueryLog())
                ->filter(fn (array $query) => preg_match('/^select count\(\*\) as [`"]?aggregate[`"]? from [`"]?comments[`"]?/i', $query['query']) === 1)
                ->count();
        } finally {
            DB::disableQueryLog();
        }

        expect($fallbackCountQueries)->toBe(0);
    })->with([
        'show' => ['GET', '/api/photos/{photo}', []],
        'update' => ['PATCH', '/api/photos/{photo}', ['taken_at' => '2024-05-01']],
    ]);

    it('returns the correct comment_count after updating a photo', function () {
        [$user, [$child]] = childrenFixture(1);
        $photo = $child->photos()->firstOrFail();
        Comment::factory()->count(2)->create(['photo_id' => $photo->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/photos/{$photo->id}", ['taken_at' => '2024-05-01'])
            ->assertOk()
            ->assertJsonPath('data.comment_count', 2);
    });
});
