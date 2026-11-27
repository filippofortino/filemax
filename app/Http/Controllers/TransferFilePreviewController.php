<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class TransferFilePreviewController
{
    /** Opening a file in the viewer: counts as previewed, never as a download, and sends no email. */
    public function store(Request $request, Transfer $transfer, TransferFile $file, TransferStorage $storage): JsonResponse
    {
        Gate::forUser($request->user())->authorize('download', $transfer);
        abort_unless($file->status === 'ready' && $file->preview_kind !== null, 404);

        $file->increment('preview_count');
        $transfer->increment('preview_count', 1, ['last_previewed_at' => now()]);
        $transfer->recordActivity($request, 'previewed', $file);

        $expires = now()->addHour()->min($transfer->expires_at);
        $url = $storage->previewUrl($file->setRelation('transfer', $transfer), $file->preview_kind === 'image' ? 'preview' : 'original', $expires);

        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    /** Serves previews from the local disk; on remote storage the browser fetches them from the bucket instead. */
    public function show(Request $request, Transfer $transfer, TransferFile $file, string $variant, TransferStorage $storage): Response
    {
        Gate::forUser($request->user())->authorize('download', $transfer);
        $kind = $file->status === 'ready' ? $file->preview_kind : null;
        abort_unless($kind !== null && ($variant === 'original' ? $kind !== 'image' : in_array($kind, ['image', 'pdf'], true)), 404);

        $headers = [
            'Content-Type' => $variant === 'original' ? $file->mime_type : 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        // Browsers refuse to show a PDF in a sandboxed document, and a PDF viewer runs apart from the page anyway.
        if ($kind !== 'pdf' || $variant !== 'original') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self'; media-src 'self'";
        }

        // shortcut: no Range support here, so local videos can't seek; production serves them from the bucket, which can.
        if ($variant === 'thumbnail' || $variant === 'preview') {
            // The copies are small WebPs, so they go out whole; only originals stream.
            $copy = $storage->disk()->get($file->previewPath($variant));
            abort_if($copy === null, 404);

            return response($copy, 200, $headers + ['Content-Disposition' => HeaderUtils::makeDisposition('inline', $file->safeName(), str_replace('%', '', Str::ascii($file->safeName())) ?: 'preview')]);
        }

        return $storage->disk()->response($file->path, $file->safeName(), $headers);
    }
}
