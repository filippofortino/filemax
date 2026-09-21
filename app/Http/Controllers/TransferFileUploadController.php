<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\TransferFileResource;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use App\Services\TransferUploadMutation;
use Illuminate\Http\JsonResponse;

final class TransferFileUploadController
{
    public function store(Transfer $transfer, TransferFile $file, TransferStorage $storage, TransferUploadMutation $mutation): JsonResponse
    {
        return $mutation->run($transfer, $file, function (TransferFile $file) use ($storage): JsonResponse {
            $storage->complete($file);

            return response()->json(['file' => new TransferFileResource($file)]);
        });
    }

    public function destroy(Transfer $transfer, TransferFile $file, TransferStorage $storage, TransferUploadMutation $mutation): JsonResponse
    {
        return $mutation->run($transfer, $file, function (TransferFile $file) use ($storage): JsonResponse {
            $storage->abort($file);
            abort_unless($storage->disk()->delete($file->path), 503, 'Could not remove this file. Please retry.');

            $file->delete();

            return response()->json(['removed' => true]);
        });
    }
}
