<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'photo_id' => Photo::factory(),
            'user_id' => User::factory(),
            'body_encrypted' => bin2hex(random_bytes(64)),
            'iv_body' => bin2hex(random_bytes(16)),
        ];
    }
}
