<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\Child;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Album>
 */
class AlbumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'child_id' => Child::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'created_by' => User::factory(),
        ];
    }
}
