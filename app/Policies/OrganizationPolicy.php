<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

final class OrganizationPolicy
{
    /** Personal has nothing to manage: it is always just its owner. */
    public function update(User $user, Organization $organization): bool
    {
        return $user->hasVerifiedEmail() && ! $organization->personal && $organization->hasAdmin($user);
    }
}
