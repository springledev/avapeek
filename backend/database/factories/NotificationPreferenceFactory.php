<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'child_id' => Child::factory(),
            'notify_photos' => true,
            'notify_comments' => true,
            'notify_joins' => true,
            'notify_admin_comments' => true,
            'notify_access_requests' => true,
            'notify_access_granted' => true,
        ];
    }
}
