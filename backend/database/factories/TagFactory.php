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
            'created_by' => User::factory(),
            'name_encrypted' => base64_encode(random_bytes(24)),
            'iv_name' => bin2hex(random_bytes(16)),
        ];
    }
}
