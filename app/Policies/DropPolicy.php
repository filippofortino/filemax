<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Drop;
use App\Models\User;

final class DropPolicy
{
    public function create(User $user): bool
    {
        return $user->isEligible() && $user->hasVerifiedEmail();
    }

    public function update(User $user, Drop $drop): bool
    {
        return $this->create($user) && $drop->user_id === $user->id;
    }
}
