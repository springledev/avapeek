<?php

use App\Models\Child;
use App\Models\ChildUser;
use App\Models\StorageUsage;
use App\Models\User;

describe('POST /api/children', function () {
    it('creates a child profile and assigns the user as owner', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/children', [
                'name' => 'Emma',
                'birth_date' => '2024-03-15',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Emma')
            ->assertJsonPath('data.birth_date', '2024-03-15')
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.relationship', 'parent')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.storage_used', 0);

        $this->assertDatabaseHas('children', [
            'name' => 'Emma',
            'created_by' => $user->id,
        ]);

        $childId = $response->json('data.id');

        $this->assertDatabaseHas('child_users', [
            'child_id' => $childId,
            'user_id' => $user->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);

        $this->assertDatabaseHas('storage_usage', [
            'child_id' => $childId,
            'used_bytes' => 0,
        ]);
    });

    it('assigns an avatar_color from the palette', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/children', ['name' => 'Emma']);

        $color = $response->json('data.avatar_color');

        expect($color)->toMatch('/^#[0-9A-F]{6}$/');
    });

    it('allows creating a child without a birth_date', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/children', ['name' => 'Emma']);

        $response->assertStatus(201)
            ->assertJsonPath('data.birth_date', null)
            ->assertJsonPath('data.age', null);
    });

    it('requires authentication', function () {
        $response = $this->postJson('/api/children', ['name' => 'Emma']);

        $response->assertStatus(401);
    });

    it('validates required fields', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/children', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    it('validates name max length', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/children', [
                'name' => str_repeat('a', 101),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    it('validates birth_date format', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/children', [
                'name' => 'Emma',
                'birth_date' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date']);
    });
});

describe('GET /api/children', function () {
    it('returns children the authenticated user belongs to', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $child1 = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child1->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);
        StorageUsage::create(['child_id' => $child1->id, 'used_bytes' => 0]);

        $child2 = Child::factory()->create(['created_by' => $otherUser->id]);
        ChildUser::create([
            'child_id' => $child2->id,
            'user_id' => $user->id,
            'role' => 'member',
            'relationship' => 'grandparent',
        ]);
        ChildUser::create([
            'child_id' => $child2->id,
            'user_id' => $otherUser->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);
        StorageUsage::create(['child_id' => $child2->id, 'used_bytes' => 0]);

        $child3 = Child::factory()->create(['created_by' => $otherUser->id]);
        ChildUser::create([
            'child_id' => $child3->id,
            'user_id' => $otherUser->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/children');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        expect($ids)->toContain($child1->id);
        expect($ids)->toContain($child2->id);
        expect($ids)->not->toContain($child3->id);
    });

    it('returns correct role and relationship for each child', function () {
        $user = User::factory()->create();

        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/children');

        $response->assertJsonPath('data.0.role', 'owner')
            ->assertJsonPath('data.0.relationship', 'parent');
    });

    it('includes member_count in the response', function () {
        $user = User::factory()->create();
        $member = User::factory()->create();

        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'role' => 'member',
            'relationship' => 'grandparent',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/children');

        $response->assertJsonPath('data.0.member_count', 2);
    });

    it('includes age when birth_date is set', function () {
        $user = User::factory()->create();

        $child = Child::factory()->create([
            'created_by' => $user->id,
            'birth_date' => now()->subYears(2),
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/children');

        expect($response->json('data.0.age'))->toContain('years');
    });

    it('requires authentication', function () {
        $response = $this->getJson('/api/children');

        $response->assertStatus(401);
    });
});

describe('GET /api/children/{child}', function () {
    it('returns child details including members', function () {
        $user = User::factory()->create();
        $member = User::factory()->create([
            'display_name' => 'Grandma Sue',
        ]);

        $child = Child::factory()->create([
            'name' => 'Emma',
            'birth_date' => '2024-03-15',
            'created_by' => $user->id,
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'relationship' => 'parent',
        ]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $member->id,
            'role' => 'member',
            'relationship' => 'grandparent',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 1024]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Emma')
            ->assertJsonPath('data.birth_date', '2024-03-15')
            ->assertJsonPath('data.storage_used', 1024);

        $members = $response->json('data.members');
        expect($members)->toHaveCount(2);
        expect(collect($members)->pluck('display_name')->toArray())
            ->toContain('Grandma Sue');
    });

    it('prevents non-members from viewing child details', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();

        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/children/{$child->id}");

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $child = Child::factory()->create();

        $response = $this->getJson("/api/children/{$child->id}");

        $response->assertStatus(401);
    });
});

describe('POST /api/encryption-keys', function () {
    it('stores a wrapped DEK for a child the user is a member of', function () {
        $user = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $user->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        StorageUsage::create(['child_id' => $child->id, 'used_bytes' => 0]);

        $wrappedDek = base64_encode(random_bytes(64));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/encryption-keys', [
                'child_id' => $child->id,
                'wrapped_dek' => $wrappedDek,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.child_id', $child->id)
            ->assertJsonPath('data.key_version', 1);

        $this->assertDatabaseHas('encryption_keys', [
            'child_id' => $child->id,
            'user_id' => $user->id,
            'encrypted_dek' => $wrappedDek,
            'key_version' => 1,
        ]);
    });

    it('prevents storing a DEK for a child the user is not a member of', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $child = Child::factory()->create(['created_by' => $owner->id]);
        ChildUser::create([
            'child_id' => $child->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/encryption-keys', [
                'child_id' => $child->id,
                'wrapped_dek' => base64_encode(random_bytes(64)),
            ]);

        $response->assertStatus(403);
    });

    it('requires authentication', function () {
        $response = $this->postJson('/api/encryption-keys', [
            'child_id' => 1,
            'wrapped_dek' => 'test',
        ]);

        $response->assertStatus(401);
    });

    it('validates required fields', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/encryption-keys', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['child_id', 'wrapped_dek']);
    });
});
