<?php

namespace Database\Factories;

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
        $mimeType = fake()->randomElement(['image/jpeg', 'image/png', 'image/webp', 'image/heic']);
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
        };

        $uuid = fake()->uuid();

        return [
            'uuid' => $uuid,
            'uploaded_by' => User::factory(),
            'original_path' => "photos/{$uuid}.{$ext}.enc",
            'thumbnail_path' => "photos/{$uuid}_thumb.webp.enc",
            'iv_original' => bin2hex(random_bytes(16)),
            'iv_thumbnail' => bin2hex(random_bytes(16)),
            'original_mime' => $mimeType,
            'size_bytes' => fake()->numberBetween(100000, 5000000),
            'stored_bytes' => fake()->numberBetween(100000, 5000000),
            'width' => fake()->numberBetween(800, 4000),
            'height' => fake()->numberBetween(600, 3000),
            'exif_encrypted' => null,
            'iv_exif' => null,
            'taken_at' => fake()->optional()->dateTimeBetween('-2 years', 'now'),
        ];
    }
}
