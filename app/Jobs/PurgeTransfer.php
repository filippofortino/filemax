<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transfer;
use App\Services\TransferStorage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[Tries(3)]
#[UniqueFor(3700)]
final class PurgeTransfer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public string $transferId) {}

    public function uniqueId(): string
    {
        return $this->transferId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TransferStorage $storage): void
    {
        $lock = Cache::lock('transfer-bytes:'.$this->transferId, 3700);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            $transfer = Transfer::query()->find($this->transferId);

            if (! $transfer || $transfer->purged_at) {
                return;
            }

            $expired = $transfer->status === 'ready' && $transfer->expires_at?->lte(now()->subDays(30));
            $abandoned = $transfer->status === 'uploading' && $transfer->updated_at->lte(now()->subDay());

            if (! $transfer->revoked_at && ! $expired && ! $abandoned) {
                return;
            }

            foreach ($transfer->files()->cursor() as $file) {
                $storage->abort($file);

                throw_unless($storage->disk()->delete($file->path), RuntimeException::class, 'Could not delete a transfer file.');
            }

            throw_unless($storage->disk()->delete($transfer->archive_path ?: 'archives/'.$transfer->id.'.zip'), RuntimeException::class, 'Could not delete the transfer archive.');

            throw_unless(Storage::disk('local')->deleteDirectory('uploads/'.$transfer->id), RuntimeException::class, 'Could not delete temporary upload parts.');

            $transfer->forceFill(['purged_at' => now(), 'archive_path' => null, 'archive_status' => null, 'archive_progress' => 0])->save();
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('Transfer cleanup failed.'));
    }
}
