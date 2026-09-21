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
        abort_unless($transfer->archive_status === 'ready' && $transfer->archive_path, 409);
        $name = (Str::slug($transfer->displayTitle()) ?: 'filemax-transfer').'.zip';

        if ($storage->isRemote()) {
            return redirect()->away($storage->disk()->temporaryUrl($transfer->archive_path, now()->addMinutes(5)->min($transfer->expires_at), [
                'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', $name, str_replace('%', '', Str::ascii($name)) ?: 'download'),
                'ResponseContentType' => 'application/zip',
            ]));
        }

        return $storage->disk()->download($transfer->archive_path, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }
}
