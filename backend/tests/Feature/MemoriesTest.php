<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * @return array{User, Child}
 */
function memoriesMember(): array
{
    $user = User::factory()->create();
    $child = Child::factory()->create(['created_by' => $user->id]);
    ChildUser::create(['child_id' => $child->id, 'user_id' => $user->id, 'role' => 'owner']);

    return [$user, $child];
}

function memoryPhoto(Child $child, ?string $takenAt): Photo
{
    $photo = Photo::factory()->create(['taken_at' => $takenAt]);
    $photo->children()->attach($child->id, [
        'encrypted_pek' => bin2hex(random_bytes(32)),
        'iv_pek' => bin2hex(random_bytes(16)),
    ]);

    return $photo;
}

describe('GET /api/memories', function () {
    it('rejects guests', function () {
        $this->getJson('/api/memories?date=2025-10-25&tz=UTC')->assertUnauthorized();
    });

    it('returns prior-year matches and excludes the current year', function () {
        [$user, $child] = memoriesMember();
        $a = memoryPhoto($child, '2024-10-25 12:00:00');
        $b = memoryPhoto($child, '2022-10-25 12:00:00');
        memoryPhoto($child, '2025-10-25 12:00:00');
        memoryPhoto($child, '2024-10-26 12:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-25&tz=UTC')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.1.id', $b->id);
    });

    it('never returns photos of children the user is not a member of', function () {
        [$user] = memoriesMember();
        [, $otherChild] = memoriesMember();
        memoryPhoto($otherChild, '2024-10-25 12:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-25&tz=UTC')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['total' => 0]]);
    });

    it('lists a photo shared by two children once', function () {
        [$user, $child] = memoriesMember();
        $second = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create(['child_id' => $second->id, 'user_id' => $user->id, 'role' => 'owner']);
        $photo = memoryPhoto($child, '2024-10-25 12:00:00');
        $photo->children()->attach($second->id, ['encrypted_pek' => 'x', 'iv_pek' => 'y']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-25&tz=UTC')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    });

    it('interprets taken_at in the requested time zone', function () {
        [$user, $child] = memoriesMember();
        // 2024-10-25 01:30 in Europe/Amsterdam (CEST)
        $photo = memoryPhoto($child, '2024-10-24 23:30:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-25&tz=Europe/Amsterdam')
            ->assertOk()
            ->assertJsonPath('data.0.id', $photo->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-24&tz=Europe/Amsterdam')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('never matches photos without taken_at', function () {
        [$user, $child] = memoriesMember();
        memoryPhoto($child, null);
        memoryPhoto($child, '2024-10-25 12:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-25&tz=UTC')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('matches Feb 29 only in leap years', function () {
        [$user, $child] = memoriesMember();
        $leap = memoryPhoto($child, '2024-02-29 12:00:00');
        memoryPhoto($child, '2023-03-01 12:00:00');
        memoryPhoto($child, '2023-02-28 12:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2028-02-29&tz=UTC')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $leap->id);
    });

    it('returns 422 for invalid input', function (string $query) {
        [$user] = memoriesMember();

        $this->actingAs($user, 'sanctum')->getJson("/api/memories{$query}")->assertUnprocessable();
    })->with([
        'missing both' => [''],
        'bad date' => ['?date=25-10-2025&tz=UTC'],
        'impossible date' => ['?date=2025-02-30&tz=UTC'],
        'bad tz' => ['?date=2025-10-25&tz=Mars/Base'],
        'missing tz' => ['?date=2025-10-25'],
    ]);

    it('caps the response at 48 items and reports the full total', function () {
        [$user, $child] = memoriesMember();
        for ($i = 0; $i < 50; $i++) {
            memoryPhoto($child, '2024-10-25 12:00:00');
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/memories?date=2025-10-25&tz=UTC')
            ->assertOk()
            ->assertJsonCount(48, 'data')
            ->assertJsonPath('meta.total', 50);
    });

    it('exposes only the feed photo fields plus thumbnail_url', function () {
        [$user, $child] = memoriesMember();
        memoryPhoto($child, '2024-10-25 12:00:00');

        $memory = $this->actingAs($user, 'sanctum')->getJson('/api/memories?date=2025-10-25&tz=UTC')->json('data.0');
        $feed = $this->actingAs($user, 'sanctum')->getJson('/api/photos')->json('data.0');

        expect(array_keys($memory))->toEqualCanonicalizing(array_keys($feed));
    });

    it('keeps the query count independent of the number of matches', function () {
        [$user, $child] = memoriesMember();
        memoryPhoto($child, '2024-10-25 12:00:00');

        $count = function () use ($user) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user, 'sanctum')->getJson('/api/memories?date=2025-10-25&tz=UTC')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $count();
        for ($i = 0; $i < 10; $i++) {
            memoryPhoto($child, '2023-10-25 12:00:00');
        }

        expect($count())->toBe($one);
    });
});
