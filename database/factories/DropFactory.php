<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Drop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Drop> */
final class DropFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => Str::random(16),
            'title' => fake()->sentence(3),
            'closes_at' => now()->addDays(7),
            'keep_days' => 30,
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (): array => ['closed_at' => now()->subHour()]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['closes_at' => now()->subHour()]);
    }
}
