<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\StorageUsage;
use App\Models\User;

describe('GET /api/children/{child}/quota', function () {
    it('returns storage usage and dynamic limit for a child', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 1024]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/quota");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['used_bytes', 'limit_bytes', 'percentage'],
            ])
            ->assertJsonPath('data.used_bytes', 1024)
            ->assertJsonPath('data.limit_bytes', 5 * 1024 * 1024 * 1024);
    });

    it('returns zero used_bytes and 0 percentage for empty child', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/quota");

        $response->assertStatus(200)
            ->assertJsonPath('data.used_bytes', 0)
            ->assertJsonPath('data.percentage', 0);
    });

    it('calculates percentage correctly', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $limit = 5 * 1024 * 1024 * 1024;
        $used = (int) ($limit * 0.5);

        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => $used]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/quota");

        $response->assertStatus(200)
            ->assertJsonPath('data.percentage', 50);
    });

    it('blocks non-members from viewing quota', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}/quota");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->getJson("/api/children/{$child->id}/quota");

        $response->assertStatus(401);
    });
});
