<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transfer;
use App\Models\User;

final class TransferPolicy
{
    public function create(User $user): bool
    {
        return $user->isEligible() && $user->hasVerifiedEmail();
    }

    public function view(User $user, Transfer $transfer): bool
    {
        return $this->create($user) && $transfer->user_id === $user->id;
    }

    public function update(User $user, Transfer $transfer): bool
    {
        return $this->view($user, $transfer);
    }

    public function delete(User $user, Transfer $transfer): bool
    {
        return $this->view($user, $transfer);
    }

    public function download(?User $user, Transfer $transfer): bool
    {
        if (! $transfer->isAvailable()) {
            return false;
        }

        if ($transfer->visibility === 'public') {
            return true;
        }

        if (! $user instanceof User || ! $this->create($user)) {
            return false;
        }

        return $transfer->user_id === $user->id || $transfer->teams()
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))->exists();
    }
}
