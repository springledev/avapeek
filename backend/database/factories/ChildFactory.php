<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Child>
 */
class ChildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'birth_date' => fake()->optional()->date(),
            'avatar_color' => fake()->hexColor(),
            'created_by' => User::factory(),
        ];
    }
}
