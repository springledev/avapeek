<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Stands in for the client-derived auth hash of every default user.
     */
    public const AUTH_HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * Hashing is slow by design, so the hashes are made once and reused.
     */
    protected static ?string $v2Hash = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$v2Hash ??= Hash::make(self::AUTH_HASH),
            'display_name' => fake()->name(),
            'master_key_salt' => bin2hex(random_bytes(32)),
            'public_key' => fake()->regexify('[A-Za-z0-9]{128}'),
            'encrypted_private_key' => fake()->regexify('[A-Za-z0-9]{128}'),
        ];
    }
}
