<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transfer;
use App\Services\TransferStorage;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

#[MaxExceptions(3)]
#[Timeout(3600)]
#[Tries(0)]
#[UniqueFor(11100)]
final class PurgeTransfer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private DateTimeInterface $retryDeadline;

    public function __construct(public string $transferId)
    {
        $this->retryDeadline = now()->addHours(2);
    }

    public function uniqueId(): string
    {
        return $this->transferId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline ??= now()->addHours(2);
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
            $this->release(60);

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

            $archivePath = $transfer->archive_path ?: 'archives/'.$transfer->id.'.zip';
            $storage->abortArchiveUploads($archivePath);

            throw_unless($storage->disk()->delete($archivePath), RuntimeException::class, 'Could not delete the transfer archive.');

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
