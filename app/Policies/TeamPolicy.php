<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin && $user->isEligible() && $user->hasVerifiedEmail();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }
}
