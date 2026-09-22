<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TransferStorageException;
use App\Models\Transfer;
use App\Models\TransferFile;
use Aws\Exception\AwsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use League\Flysystem\FilesystemException;

final readonly class TransferUploadMutation
{
    public function __construct(private TransferStorage $storage) {}

    /** @param callable(TransferFile): JsonResponse $callback */
    public function run(Transfer $transfer, TransferFile $file, callable $callback): JsonResponse
    {
        Gate::authorize('update', $transfer);

        return $this->storage->withTransferLock($transfer, function (Transfer $transfer) use ($file, $callback): JsonResponse {
            abort_if($transfer->revoked_at !== null || $transfer->purged_at !== null, 410, 'This transfer was cancelled.');
            abort_unless($transfer->status === 'uploading', 409, 'This transfer has already been published.');
            $transfer->touch();

            try {
                return $callback($transfer->files()->findOrFail($file->id));
            } catch (TransferStorageException|AwsException|FilesystemException $exception) {
                report($exception);

                return response()->json(['message' => 'Storage is temporarily unavailable. Your completed files are safe; retry the missing files.'], 503);
            }
        });
    }
}
