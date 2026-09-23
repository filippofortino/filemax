<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeTransfer;
use App\Models\Transfer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

#[Description('Purge deleted transfers, expired originals after 30 days, and abandoned uploads')]
#[Signature('filemax:cleanup')]
final class CleanupTransfersCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        Transfer::query()->whereNull('purged_at')->where(function (Builder $query): void {
            $query->whereNotNull('revoked_at')
                ->orWhere(fn (Builder $expired) => $expired->where('status', 'ready')->where('expires_at', '<=', now()->subDays(30)))
                ->orWhere(fn (Builder $drafts) => $drafts->where('status', 'uploading')->where('updated_at', '<=', now()->subDay()));
        })->lazyById()->each(function (Transfer $transfer) use (&$count): void {
            dispatch(new PurgeTransfer($transfer->id));
            $count++;
        });

        Transfer::query()->whereIn('archive_status', ['pending', 'processing'])
            ->where('archive_requested_at', '<=', now()->subHours(4))
            ->update(['archive_status' => 'failed', 'archive_progress' => 0]);

        $temporaryDirectory = storage_path('app/archive-tmp');

        if (File::isDirectory($temporaryDirectory)) {
            foreach (glob($temporaryDirectory.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                if (File::lastModified($directory) <= now()->subHours(2)->getTimestamp()) {
                    File::deleteDirectory($directory);
                }
            }
        }

        $this->components->info("Queued {$count} transfers for cleanup.");

        return self::SUCCESS;
    }
}
