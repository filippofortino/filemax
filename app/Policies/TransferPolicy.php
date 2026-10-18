<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Drop;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class TransferPolicy
{
    public function create(User $user): bool
    {
        return $user->isEligible() && $user->hasVerifiedEmail();
    }

    public function view(User $user, Transfer $transfer): bool
    {
        return $this->create($user) && $transfer->user_id === $user->id && $transfer->drop_id === null;
    }

    public function viewReceived(User $user, Transfer $transfer): bool
    {
        return $this->create($user) && $transfer->user_id === $user->id && $transfer->drop_id !== null;
    }

    /** Whoever started a Drop submission may keep uploading to it from the same session, for a day after the Drop closes. */
    public function submit(?User $user, Transfer $transfer): bool
    {
        return $transfer->drop instanceof Drop
            && request()->hasSession()
            && request()->session()->get('drop_uploads.'.$transfer->id) === true
            && now()->lt($transfer->drop->acceptsUploadsUntil());
    }

    public function upload(?User $user, Transfer $transfer): bool
    {
        if ($transfer->drop_id !== null) {
            return $this->submit($user, $transfer);
        }

        return $user instanceof User && $this->update($user, $transfer);
    }

    public function update(User $user, Transfer $transfer): bool
    {
        return $this->view($user, $transfer);
    }

    public function delete(User $user, Transfer $transfer): bool
    {
        return $this->view($user, $transfer);
    }

    public function download(?User $user, Transfer $transfer): bool|Response
    {
        if (! $transfer->isAvailable()) {
            return Response::denyWithStatus(410);
        }

        if ($transfer->visibility === 'public') {
            return $transfer->password_hash === null
                || $user?->id === $transfer->user_id
                || (request()->hasSession() && request()->session()->get('unlocked_transfers.'.$transfer->id) === true);
        }

        if (! $user instanceof User || ! $this->create($user)) {
            return false;
        }

        return $transfer->user_id === $user->id || $transfer->teams()
            ->whereHas('users', fn ($query) => $query->whereKey($user->id))->exists();
    }
}
