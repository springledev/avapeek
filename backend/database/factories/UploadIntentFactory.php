<?php

namespace Database\Factories;

use App\Models\UploadIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UploadIntent>
 */
class UploadIntentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) fake()->uuid();

        return [
            'uuid' => $uuid,
            'user_id' => User::factory(),
            'original_path' => "photos/{$uuid}.jpg.enc",
            'thumbnail_path' => "photos/{$uuid}_thumb.webp.enc",
            'original_mime' => 'image/jpeg',
            'expected_original_bytes' => 1000,
            'expected_thumbnail_bytes' => 100,
            'reserved_bytes' => 1100,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
