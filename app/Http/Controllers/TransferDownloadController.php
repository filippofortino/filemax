<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

    private function authorized(Request $request, string $token, bool $lock = false): Transfer
    {
        $transfer = Transfer::query()->where('token', $token)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($transfer->isAvailable(), 410);
        Gate::forUser($request->user())->authorize('download', $transfer);

        return $transfer;
    }

    private function disposition(string $name): string
    {
        return HeaderUtils::makeDisposition('attachment', $name, str_replace('%', '', Str::ascii($name)) ?: 'download');
    }
}
