<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'child_id' => Child::factory(),
            'token' => Str::random(64),
            'email' => null,
            'role' => 'member',
            'relationship' => null,
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Mark the invite as expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Mark the invite as accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }
}
