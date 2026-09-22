<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use App\Services\TransferUploadMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TransferUploadPartController
{
    public function store(Transfer $transfer, TransferFile $file, int $part, TransferStorage $storage, TransferUploadMutation $mutation): JsonResponse
    {
        return $mutation->run($transfer, $file, fn (TransferFile $file): JsonResponse => response()->json($storage->sign($file, $part)));
    }

    public function update(Request $request, Transfer $transfer, TransferFile $file, int $part, TransferStorage $storage, TransferUploadMutation $mutation): JsonResponse
    {
        return $mutation->run($transfer, $file, function (TransferFile $file) use ($request, $part, $storage): JsonResponse {
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
}
