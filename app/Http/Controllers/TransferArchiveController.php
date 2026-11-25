<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PrepareTransferArchive;
use App\Models\Transfer;
use App\Models\TransferArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Zips the whole transfer, or one of its folders when the request names a `folder`. */
final class TransferArchiveController
{
    public function store(Request $request, Transfer $transfer): JsonResponse
    {
        Gate::forUser($request->user())->authorize('download', $transfer);
        $folder = $this->folder($request, $transfer);
        $prepare = false;
        $archive = DB::transaction(function () use ($request, $transfer, $folder, &$prepare): Transfer|TransferArchive {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            Gate::forUser($request->user())->authorize('download', $transfer);
            $transfer->recordDownload($request->user());
            // The transfer's row lock keeps two requests for the same folder from creating two archives.
            $archive = $folder === null ? $transfer : $transfer->archives()->firstOrNew(['folder' => $folder]);

            if ($archive instanceof TransferArchive) {
                $archive->download_count++;
            }

            $prepare = ! in_array($archive->archive_status, ['pending', 'processing', 'ready'], true);
            if ($prepare) {
                $archive->forceFill(['archive_status' => 'pending', 'archive_progress' => 0, 'archive_requested_at' => now()]);
            }

            $archive->save();

            return $archive;
        }, attempts: 5);

        if ($prepare) {
            dispatch(new PrepareTransferArchive($transfer->id, $archive->archive_requested_at?->format('Y-m-d H:i:s'), $folder));
        }

        return $this->archiveResponse($transfer, $archive->refresh(), $folder);
    }

    public function show(Request $request, Transfer $transfer): JsonResponse
    {
        Gate::forUser($request->user())->authorize('download', $transfer);
        $folder = $this->folder($request, $transfer);
        $archive = $folder === null ? $transfer : $transfer->archives()->firstOrNew(['folder' => $folder]);

        return $this->archiveResponse($transfer, $archive, $folder);
    }

    private function archiveResponse(Transfer $transfer, Transfer|TransferArchive $archive, ?string $folder): JsonResponse
    {
        return response()->json([
            'status' => $archive->archive_status,
            'progress' => $archive->archive_progress,
            'url' => $archive->archive_status === 'ready' ? route('shared.archive.download', ['transfer' => $transfer->token, 'folder' => $folder]) : null,
        ])->header('Cache-Control', 'no-store');
    }

    /** The folder the request names, after checking the transfer has it; null for the whole transfer. */
    private function folder(Request $request, Transfer $transfer): ?string
    {
        $folder = $request->filled('folder') ? $request->string('folder')->toString() : null;
        abort_if($folder !== null && ! $transfer->hasFolder($folder), 404);

        return $folder;
    }
}
