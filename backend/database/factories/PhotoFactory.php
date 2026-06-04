<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\Child;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    public function definition(): array
    {
        $uuid = fake()->uuid();

        return [
            'album_id' => Album::factory(),
            'child_id' => Child::factory(),
            'uploaded_by' => User::factory(),
            'original_path' => "{$uuid}/{$uuid}.webp.enc",
            'thumbnail_sm_path' => "{$uuid}/{$uuid}_sm.webp.enc",
            'thumbnail_md_path' => "{$uuid}/{$uuid}_md.webp.enc",
            'thumbnail_lg_path' => "{$uuid}/{$uuid}_lg.webp.enc",
            'iv_original' => bin2hex(random_bytes(16)),
            'iv_sm' => bin2hex(random_bytes(16)),
            'iv_md' => bin2hex(random_bytes(16)),
            'iv_lg' => bin2hex(random_bytes(16)),
            'mime_type' => 'image/webp',
            'size_bytes' => fake()->numberBetween(100000, 5000000),
            'width' => fake()->numberBetween(800, 4000),
            'height' => fake()->numberBetween(600, 3000),
            'exif_encrypted' => null,
            'iv_exif' => null,
            'thumbnail_status' => 'ready',
            'taken_at' => fake()->optional()->dateTimeBetween('-2 years', 'now'),
        ];
    }
}
