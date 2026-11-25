<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Services\TransferStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class TransferArchiveDownloadController
{
    public function show(Request $request, Transfer $transfer, TransferStorage $storage): Response
    {
        Gate::forUser($request->user())->authorize('download', $transfer);
        $folder = $request->filled('folder') ? $request->string('folder')->toString() : null;
        abort_if($folder !== null && ! $transfer->hasFolder($folder), 404);
        $archive = $folder === null ? $transfer : $transfer->archives()->where('folder', $folder)->first();
        abort_unless($archive?->archive_status === 'ready' && $archive->archive_path, 409);
        $name = (Str::slug($folder === null ? $transfer->displayTitle() : Str::afterLast($folder, '/')) ?: 'filemax-transfer').'.zip';

        if ($storage->isRemote()) {
            return redirect()->away($storage->disk()->temporaryUrl($archive->archive_path, now()->addMinutes(5)->min($transfer->expires_at), [
                'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', $name, str_replace('%', '', Str::ascii($name)) ?: 'download'),
                'ResponseContentType' => 'application/zip',
            ]))->header('Cache-Control', 'private, no-store');
        }

        return $storage->disk()->download($archive->archive_path, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }
}
