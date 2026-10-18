<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Drop;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Transfer> */
final class TransferFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'token' => Str::random(64), 'title' => fake()->sentence(3), 'visibility' => 'public', 'status' => 'ready', 'expires_in_days' => 7, 'expires_at' => now()->addDays(7)];
    }

    public function uploading(): self
    {
        return $this->state(fn (): array => ['status' => 'uploading', 'expires_at' => null]);
    }

    /** A submission that arrived through a Drop owned by the same user. */
    public function received(): self
    {
        return $this->state(fn (array $attributes): array => [
            'drop_id' => Drop::factory()->state(['user_id' => $attributes['user_id']]),
            'visibility' => 'drop',
            'sender_name' => fake()->name(),
            'expires_in_days' => 30,
            'expires_at' => now()->addDays(30),
            'received_at' => now(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
