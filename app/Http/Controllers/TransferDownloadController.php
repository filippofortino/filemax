<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PrepareTransferArchive;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class TransferDownloadController
{
    public function file(Request $request, string $token, TransferFile $file, TransferStorage $storage): JsonResponse
    {
        $transfer = DB::transaction(function () use ($request, $token, $file): Transfer {
            $transfer = $this->authorized($request, $token, true);
            abort_unless($file->transfer_id === $transfer->id && $file->status === 'ready', 404);
            $transfer->increment('download_count', 1, ['last_downloaded_at' => now()]);
            $file->increment('download_count');

            return $transfer;
        }, attempts: 5);

        $expires = now()->addMinutes(5)->min($transfer->expires_at);
        $url = $storage->isRemote()
            ? $storage->disk()->temporaryUrl($file->path, $expires, [
                'ResponseContentDisposition' => $this->disposition($file->safeName()),
                'ResponseContentType' => 'application/octet-stream',
            ])
            : URL::temporarySignedRoute('shared.files.content', $expires, ['token' => $token, 'file' => $file->id]);

        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    public function content(Request $request, string $token, TransferFile $file, TransferStorage $storage): Response
    {
        $transfer = $this->authorized($request, $token);
        abort_unless($file->transfer_id === $transfer->id && $file->status === 'ready', 404);

        return $storage->disk()->download($file->path, $file->safeName(), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function all(Request $request, string $token): JsonResponse
    {
        $prepare = false;
        $transfer = DB::transaction(function () use ($request, $token, &$prepare): Transfer {
            $transfer = $this->authorized($request, $token, true);
            $transfer->increment('download_count', 1, ['last_downloaded_at' => now()]);

            if (! in_array($transfer->archive_status, ['pending', 'processing', 'ready'], true)) {
                $transfer->forceFill(['archive_status' => 'pending', 'archive_progress' => 0, 'archive_requested_at' => now()])->save();
                $prepare = true;
            }

            return $transfer;
        }, attempts: 5);

        if ($prepare) {
            dispatch(new PrepareTransferArchive($transfer->id));
        }

        return $this->archiveResponse($transfer->refresh());
    }

    public function archive(Request $request, string $token): JsonResponse
    {
        return $this->archiveResponse($this->authorized($request, $token));
    }

    public function archiveDownload(Request $request, string $token, TransferStorage $storage): Response
    {
        $transfer = $this->authorized($request, $token);
        abort_unless($transfer->archive_status === 'ready' && $transfer->archive_path, 409);
        $name = (Str::slug($transfer->displayTitle()) ?: 'filemax-transfer').'.zip';

        if ($storage->isRemote()) {
            return redirect()->away($storage->disk()->temporaryUrl($transfer->archive_path, now()->addMinutes(5)->min($transfer->expires_at), [
                'ResponseContentDisposition' => $this->disposition($name),
                'ResponseContentType' => 'application/zip',
            ]));
        }

        return $storage->disk()->download($transfer->archive_path, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }

    private function authorized(Request $request, string $token, bool $lock = false): Transfer
    {
        $transfer = Transfer::query()->where('token', $token)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($transfer->isAvailable(), 410);
        Gate::forUser($request->user())->authorize('download', $transfer);

        return $transfer;
    }

    private function archiveResponse(Transfer $transfer): JsonResponse
    {
        return response()->json([
            'status' => $transfer->archive_status,
            'progress' => $transfer->archive_progress,
            'url' => $transfer->archive_status === 'ready' ? route('shared.archive.download', $transfer->token) : null,
        ])->header('Cache-Control', 'no-store');
    }

    private function disposition(string $name): string
    {
        return HeaderUtils::makeDisposition('attachment', $name, str_replace('%', '', Str::ascii($name)) ?: 'download');
    }
}
