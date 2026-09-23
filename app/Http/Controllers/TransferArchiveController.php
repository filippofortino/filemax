<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PrepareTransferArchive;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class TransferArchiveController
{
    public function store(Request $request, Transfer $transfer): JsonResponse
    {
        $prepare = false;
        $transfer = DB::transaction(function () use ($request, $transfer, &$prepare): Transfer {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            Gate::forUser($request->user())->authorize('download', $transfer);
            $transfer->increment('download_count', 1, ['last_downloaded_at' => now()]);

            $prepare = ! in_array($transfer->archive_status, ['pending', 'processing', 'ready'], true);
            if ($prepare) {
                $transfer->forceFill(['archive_status' => 'pending', 'archive_progress' => 0, 'archive_requested_at' => now()])->save();
            }

            return $transfer;
        }, attempts: 5);

        if ($prepare) {
            dispatch(new PrepareTransferArchive($transfer->id, $transfer->archive_requested_at?->format('Y-m-d H:i:s')));
        }

        return $this->archiveResponse($transfer->refresh());
    }

    public function show(Request $request, Transfer $transfer): JsonResponse
    {
        Gate::forUser($request->user())->authorize('download', $transfer);

        return $this->archiveResponse($transfer);
    }

    private function archiveResponse(Transfer $transfer): JsonResponse
    {
        return response()->json([
            'status' => $transfer->archive_status,
            'progress' => $transfer->archive_progress,
            'url' => $transfer->archive_status === 'ready' ? route('shared.archive.download', $transfer->token) : null,
        ])->header('Cache-Control', 'no-store');
    }
}
