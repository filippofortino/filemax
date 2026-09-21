<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\TransferStorageException;
use App\Http\Resources\TransferFileResource;
use App\Http\Resources\TransferResource;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Aws\Exception\AwsException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

final class TransferUploadController
{
    public function sign(Transfer $transfer, TransferFile $file, int $part, TransferStorage $storage): JsonResponse
    {
        return $this->mutate($transfer, $storage, function (Transfer $transfer) use ($file, $part, $storage): JsonResponse {
            $file = $transfer->files()->findOrFail($file->id);

            return response()->json($storage->sign($file, $part));
        });
    }

    public function upload(Request $request, Transfer $transfer, TransferFile $file, int $part, TransferStorage $storage): JsonResponse
    {
        return $this->mutate($transfer, $storage, function (Transfer $transfer) use ($request, $file, $part, $storage): JsonResponse {
            $file = $transfer->files()->findOrFail($file->id);
            abort_if($file->status === 'ready', 409, 'This file is already complete.');
            $stream = $request->getContent(true);
            try {
                $storage->uploadPart($file, $part, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return response()->json(['completed' => true]);
        });
    }

    public function complete(Transfer $transfer, TransferFile $file, TransferStorage $storage): JsonResponse
    {
        return $this->mutate($transfer, $storage, function (Transfer $transfer) use ($file, $storage): JsonResponse {
            $file = $transfer->files()->findOrFail($file->id);
            $storage->complete($file);

            return response()->json(['file' => new TransferFileResource($file)]);
        });
    }

    public function remove(Transfer $transfer, TransferFile $file, TransferStorage $storage): JsonResponse
    {
        return $this->mutate($transfer, $storage, function (Transfer $transfer) use ($file, $storage): JsonResponse {
            $file = $transfer->files()->findOrFail($file->id);
            $storage->abort($file);
            abort_unless($storage->disk()->delete($file->path), 503, 'Could not remove this file. Please retry.');

            $file->delete();

            return response()->json(['removed' => true]);
        });
    }

    public function finalize(Transfer $transfer, TransferStorage $storage): JsonResponse
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

    /** @param callable(Transfer): JsonResponse $callback */
    private function mutate(Transfer $transfer, TransferStorage $storage, callable $callback): JsonResponse
    {
        Gate::authorize('update', $transfer);

        return $storage->withTransferLock($transfer, function (Transfer $transfer) use ($callback): JsonResponse {
            abort_if($transfer->revoked_at !== null || $transfer->purged_at !== null, 410, 'This transfer was cancelled.');
            abort_unless($transfer->status === 'uploading', 409, 'This transfer has already been published.');
            $transfer->touch();

            try {
                return $callback($transfer);
            } catch (TransferStorageException|AwsException|FilesystemException $exception) {
                report($exception);

                return response()->json(['message' => 'Storage is temporarily unavailable. Your completed files are safe; retry the missing files.'], 503);
            }
        });
    }
}
