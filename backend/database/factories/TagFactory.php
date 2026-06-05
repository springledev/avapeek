<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'child_id' => Child::factory(),
            'name' => fake()->unique()->words(2, true),
            'created_by' => User::factory(),
        ];
    }
}
