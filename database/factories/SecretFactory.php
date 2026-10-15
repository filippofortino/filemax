<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Secret;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Secret> */
final class SecretFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => Str::random(32),
            'label' => fake()->sentence(3),
            'payload' => base64_encode(random_bytes(64)),
            'verifier_hash' => hash('sha256', Str::random(43)),
            'max_views' => 1,
            'expires_at' => now()->addDay(),
        ];
    }

    public function views(int $maxViews): self
    {
        return $this->state(fn (): array => ['max_views' => $maxViews]);
    }

    public function opened(): self
    {
        return $this->state(fn (array $attributes): array => ['view_count' => $attributes['max_views'] ?? 1, 'last_opened_at' => now(), 'payload' => null]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now(), 'payload' => null]);
    }
}
