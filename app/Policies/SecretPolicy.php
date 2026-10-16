<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Secret;
use App\Models\User;

final class SecretPolicy
{
    public function create(User $user): bool
    {
        return $user->isEligible() && $user->hasVerifiedEmail();
    }

    public function view(User $user, Secret $secret): bool
    {
        return $this->create($user) && $secret->user_id === $user->id;
    }

    public function delete(User $user, Secret $secret): bool
    {
        return $this->view($user, $secret);
    }
}
