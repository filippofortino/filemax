<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserSettings> */
final class UserSettingsFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'show_name_on_transfers' => true,
            'notify_transfer_expiring' => true,
            'notify_transfer_downloaded' => false,
        ];
    }
}
