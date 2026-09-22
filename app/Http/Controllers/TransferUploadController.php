<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\TransferResource;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TransferUploadController
{
    public function store(Transfer $transfer, TransferStorage $storage): JsonResponse
    {
        Gate::authorize('update', $transfer);

        return $storage->withTransferLock($transfer, function (Transfer $transfer) use ($storage): JsonResponse {
            abort_if($transfer->revoked_at !== null || $transfer->purged_at !== null, 410, 'This transfer was cancelled.');
            if ($transfer->status !== 'ready') {
                $transfer->load('files');
                if ($transfer->files->isEmpty() || $transfer->files->contains(fn (TransferFile $file): bool => $file->status !== 'ready')) {
                    throw ValidationException::withMessages(['files' => 'Complete at least one file and retry any failed files before creating your link.']);
                }

                if ($transfer->visibility === 'teams') {
                    $teamIds = $transfer->teams()->pluck('teams.id');
                    $selectableIds = $transfer->user->teams()->pluck('teams.id');
                    if ($teamIds->isEmpty() || $teamIds->diff($selectableIds)->isNotEmpty()) {
                        throw ValidationException::withMessages(['team_ids' => 'Your team membership changed. Choose teams you currently belong to before creating this link.']);
                    }
                }

                foreach ($transfer->files as $file) {
                    $storage->verify($file);
                }

                $transfer->update(['status' => 'ready', 'expires_at' => now()->addDays($transfer->expires_in_days)]);
            }

            return response()->json(['transfer' => new TransferResource($transfer->load(['files', 'teams' => fn (BelongsToMany $query) => $query->withCount('users')])), 'url' => url('/t/'.$transfer->token)]);
        });
    }
}
