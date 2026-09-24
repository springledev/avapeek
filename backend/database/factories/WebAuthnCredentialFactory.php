<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WebAuthnCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebAuthnCredential>
 */
class WebAuthnCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'user_id' => User::factory(),
            'credential_id' => base64_encode(fake()->sha256()),
            'public_key' => base64_encode(fake()->sha256()),
            'sign_count' => 0,
            'transports' => ['internal'],
            'user_chosen_name' => fake()->optional()->word(),
            'last_used_at' => fake()->optional()->dateTime(),
        ];
    }
}
