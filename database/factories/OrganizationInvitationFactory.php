<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationInvitation> */
final class OrganizationInvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'member',
            'expires_at' => now()->addDays(OrganizationInvitation::VALID_DAYS),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
