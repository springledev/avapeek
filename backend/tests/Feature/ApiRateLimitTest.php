<?php

use App\Models\User;

it('allows 180 authenticated requests a minute and then answers 429', function () {
    $user = User::factory()->create();

    foreach (range(1, 180) as $ignored) {
        $this->actingAs($user, 'sanctum')->getJson('/api/children')->assertOk();
    }

    $this->actingAs($user, 'sanctum')->getJson('/api/children')->assertStatus(429);
});

it('counts each user separately', function () {
    $busy = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 180) as $ignored) {
        $this->actingAs($busy, 'sanctum')->getJson('/api/children')->assertOk();
    }

    $this->actingAs($busy, 'sanctum')->getJson('/api/children')->assertStatus(429);
    $this->actingAs($other, 'sanctum')->getJson('/api/children')->assertOk();
});

it('keeps the login limit at 5 a minute', function () {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'auth_hash' => 'x'])
            ->assertStatus(422);
    }

    $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'auth_hash' => 'x'])
        ->assertStatus(429);
});
