<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationDomain> */
final class OrganizationDomainFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'domain' => fake()->unique()->domainName()];
    }
}
