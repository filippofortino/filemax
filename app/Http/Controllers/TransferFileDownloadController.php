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

final class TransferFileDownloadController
{
    public function store(Request $request, Transfer $transfer, TransferFile $file, TransferStorage $storage): JsonResponse
    {
        $transfer = DB::transaction(function () use ($request, $transfer, $file): Transfer {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            Gate::forUser($request->user())->authorize('download', $transfer);
            abort_unless($file->status === 'ready', 404);
            $transfer->increment('download_count', 1, ['last_downloaded_at' => now()]);
            $file->increment('download_count');

            return $transfer;
        }, attempts: 5);

        $expires = now()->addMinutes(5)->min($transfer->expires_at);
        $url = $storage->isRemote()
            ? $storage->disk()->temporaryUrl($file->path, $expires, [
                'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', $file->safeName(), str_replace('%', '', Str::ascii($file->safeName())) ?: 'download'),
                'ResponseContentType' => 'application/octet-stream',
            ])
            : URL::temporarySignedRoute('shared.files.content', $expires, ['transfer' => $transfer->token, 'file' => $file->id]);

        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Transfer $transfer, TransferFile $file, TransferStorage $storage): Response
    {
        Gate::forUser($request->user())->authorize('download', $transfer);
        abort_unless($file->status === 'ready', 404);

        return $storage->disk()->download($file->path, $file->safeName(), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
