<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transfer;
use App\Services\TransferStorage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;
use ZipStream\CompressionMethod;
use ZipStream\Exception\FileSizeIncorrectException;
use ZipStream\Stream\CallbackStreamWrapper;
use ZipStream\ZipStream;

#[FailOnTimeout]
#[MaxExceptions(3)]
#[Timeout(3600)]
#[Tries(0)]
#[UniqueFor(11100)]
final class PrepareTransferArchive implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public ?string $requestedAt = null;

    public function __construct(public string $transferId, ?string $requestedAt = null)
    {
        if ($requestedAt !== null) {
            $this->requestedAt = $requestedAt;
        }
    }

    public function uniqueId(): string
    {
        return $this->transferId;
    }

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->requestedAt)->addHours(2);
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
            $transfer = $this->currentRequest()->first();

            if (! $transfer || ! $transfer->isAvailable() || $transfer->archive_status === 'failed') {
                return;
            }

            if ($transfer->archive_status === 'ready' && $transfer->archive_path && $storage->disk()->exists($transfer->archive_path)) {
                return;
            }

            $this->currentRequest()->update(['archive_status' => 'processing', 'archive_progress' => 0]);
            $totalSize = (int) $transfer->files()->sum('size');
            throw_if($totalSize > 5492189429760, RuntimeException::class, 'This archive exceeds the storage provider’s maximum object size.');

            /** STORE entries need only headers, names and ZIP64 records beyond the source bytes. */
            $maximumSize = $totalSize + ($transfer->files()->count() * 4096) + 1024;
            $path = 'archives/'.$transfer->id.'.zip';

            $storage->writeArchive($path, $maximumSize, function (callable $write) use ($storage, $transfer, $totalSize): void {
                $written = 0;
                $progress = -1;
                $output = CallbackStreamWrapper::open(function (string $bytes) use ($write, $totalSize, &$written, &$progress): void {
                    $write($bytes);
                    $written += mb_strlen($bytes, '8bit');
                    $next = min(99, (int) floor($written / max($totalSize, 1) * 99));

                    if ($next > $progress) {
                        $this->ensureAvailable();
                        $this->currentRequest()->where('archive_status', 'processing')->update(['archive_progress' => $next]);
                        $progress = $next;
                    }
                });
                throw_if($output === false, RuntimeException::class, 'Could not create the transfer archive stream.');

                try {
                    $zip = new ZipStream(outputStream: $output, defaultCompressionMethod: CompressionMethod::STORE, enableZip64: true, defaultEnableZeroHeader: true, sendHttpHeaders: false);
                    $names = [];

                    foreach ($transfer->files()->orderBy('position')->cursor() as $file) {
                        $this->ensureAvailable();
                        $name = str_replace(['\\', ':', '*', '?', '"', '<', '>', '|'], '_', $file->safeName());
                        $stem = pathinfo($name, PATHINFO_FILENAME);
                        $extension = pathinfo($name, PATHINFO_EXTENSION);
                        $suffix = 1;

                        while (isset($names[mb_strtolower($name)])) {
                            $name = $stem.' ('.(++$suffix).')'.($extension !== '' ? '.'.$extension : '');
                        }

                        $names[mb_strtolower($name)] = true;
                        $input = $storage->readStream($file);

                        try {
                            $zip->addFileFromStream(fileName: $name, stream: $input, exactSize: $file->size);
                            throw_if(fread($input, 1) !== '', RuntimeException::class, 'The stored file size does not match the transfer.');
                        } catch (FileSizeIncorrectException $exception) {
                            throw new RuntimeException('The stored file size does not match the transfer.', $exception->getCode(), $exception);
                        } finally {
                            fclose($input);
                        }
                    }

                    $zip->finish();
                    $this->ensureAvailable();
                } finally {
                    fclose($output);
                }
            });

            $published = $this->currentRequest()->where('archive_status', 'processing')
                ->where('status', 'ready')->whereNull('revoked_at')->whereNull('purged_at')->where('expires_at', '>', now())
                ->update(['archive_status' => 'ready', 'archive_path' => $path, 'archive_progress' => 100]);

            if ($published === 0) {
                throw_unless($storage->disk()->delete($path), RuntimeException::class, 'Could not delete the cancelled transfer archive.');
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->currentRequest()->whereIn('archive_status', ['pending', 'processing'])->update(['archive_status' => 'failed', 'archive_progress' => 0]);
    }

    /** @return Builder<Transfer> */
    private function currentRequest(): Builder
    {
        return Transfer::query()->whereKey($this->transferId)->where('archive_requested_at', $this->requestedAt);
    }

    private function ensureAvailable(): void
    {
        $transfer = $this->currentRequest()->first();
        throw_unless($transfer?->isAvailable() && $transfer->archive_status === 'processing', RuntimeException::class, 'The transfer is no longer available for this archive request.');
    }
}
