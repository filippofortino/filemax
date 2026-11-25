<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transfer;
use App\Models\TransferArchive;
use App\Models\TransferFile;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

    /** Zips only this folder; null zips the whole transfer. A class default, so jobs queued before folders existed still unserialize. */
    public ?string $folder = null;

    public function __construct(public string $transferId, ?string $requestedAt = null, ?string $folder = null)
    {
        if ($requestedAt !== null) {
            $this->requestedAt = $requestedAt;
        }

        if ($folder !== null) {
            $this->folder = $folder;
        }
    }

    public function uniqueId(): string
    {
        return $this->folder === null ? $this->transferId : $this->transferId.':'.hash('sha256', $this->folder);
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
        // shortcut: one archive per transfer builds at a time, so a second folder zip waits for the first; fine at today's sizes.
        $lock = Cache::lock('transfer-bytes:'.$this->transferId, 3700);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        try {
            $transfer = Transfer::query()->find($this->transferId);
            $archive = $this->currentRequest()->first();

            if (! $transfer || ! $archive || ! $transfer->isAvailable() || $archive->archive_status === 'failed') {
                return;
            }

            if ($archive->archive_status === 'ready' && $archive->archive_path && $storage->disk()->exists($archive->archive_path)) {
                return;
            }

            $this->currentRequest()->update(['archive_status' => 'processing', 'archive_progress' => 0]);
            $files = $this->folder === null ? $transfer->files : $transfer->files->filter->isIn($this->folder);
            $totalSize = $files->sum(fn (TransferFile $file): int => $file->size);
            throw_if($totalSize > 5492189429760, RuntimeException::class, 'This archive exceeds the storage provider’s maximum object size.');

            /** STORE entries need only headers, names and ZIP64 records beyond the source bytes. Each name is written twice: local header and central directory. */
            $maximumSize = $totalSize + $files->sum(fn (TransferFile $file): int => 4096 + 2 * mb_strlen($this->entryDirectory($file).$this->entrySafe($file->safeName()), '8bit')) + 1024;
            $path = $this->folder === null ? 'archives/'.$transfer->id.'.zip' : TransferArchive::storagePath($transfer->id, $this->folder);

            $storage->writeArchive($path, $maximumSize, function (callable $write) use ($storage, $files, $totalSize): void {
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
                    // Every folder the zip will hold, so no file takes a folder's name and no folder a file's.
                    $folders = [];
                    foreach ($files as $file) {
                        $path = '';
                        foreach (array_filter(explode('/', $this->entryDirectory($file))) as $segment) {
                            $path .= $segment;
                            $folders[mb_strtolower($path)] = true;
                            $path .= '/';
                        }
                    }

                    foreach ($files as $file) {
                        $this->ensureAvailable();
                        $directory = $this->entryDirectory($file);
                        $name = $this->entrySafe($file->safeName());
                        $stem = pathinfo($name, PATHINFO_FILENAME);
                        $extension = pathinfo($name, PATHINFO_EXTENSION);
                        $suffix = 1;

                        while (isset($names[mb_strtolower($directory.$name)]) || isset($folders[mb_strtolower($directory.$name)])) {
                            $name = $stem.' ('.(++$suffix).')'.($extension !== '' ? '.'.$extension : '');
                        }

                        $names[mb_strtolower($directory.$name)] = true;
                        $input = $storage->readStream($file);

                        try {
                            $zip->addFileFromStream(fileName: $directory.$name, stream: $input, exactSize: $file->size);
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

            $available = fn (Builder $query): Builder => $query->where('status', 'ready')->whereNull('revoked_at')->whereNull('purged_at')->where('expires_at', '>', now());
            $published = $this->currentRequest()->where('archive_status', 'processing')
                ->when($this->folder === null, $available, fn (Builder $query): Builder => $query->whereHas('transfer', $available))
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

    /**
     * The row holding this request's archive_* state: the transfer itself, or its folder's archive.
     *
     * @return Builder<Transfer>|Builder<TransferArchive>
     */
    private function currentRequest(): Builder
    {
        $query = $this->folder === null
            ? Transfer::query()->whereKey($this->transferId)
            : TransferArchive::query()->where('transfer_id', $this->transferId)->where('folder', $this->folder);

        return $query->where('archive_requested_at', $this->requestedAt);
    }

    private function ensureAvailable(): void
    {
        $transfer = Transfer::query()->find($this->transferId);
        throw_unless($transfer?->isAvailable() && $this->currentRequest()->value('archive_status') === 'processing', RuntimeException::class, 'The transfer is no longer available for this archive request.');
    }

    /** The file's folder inside the zip, with a trailing slash. A folder zip starts at that folder, so it unzips as one folder. */
    private function entryDirectory(TransferFile $file): string
    {
        $parent = $this->folder !== null && str_contains($this->folder, '/') ? Str::beforeLast($this->folder, '/').'/' : '';
        $relative = $file->folder === null ? '' : mb_substr($file->folder, mb_strlen($parent));

        return Collection::make(explode('/', $relative))->filter(fn (string $segment): bool => $segment !== '')->map($this->entrySafe(...))->map(fn (string $segment): string => $segment.'/')->implode('');
    }

    /** Characters Windows can't extract, and control characters, become underscores. */
    private function entrySafe(string $name): string
    {
        // Validation already refuses "..", but a "." or ".." here would climb out of the zip, so it never passes.
        return in_array($name, ['.', '..'], true) ? '_' : (string) preg_replace('/[\\\\:*?"<>|\x00-\x1F\x7F]/u', '_', $name);
    }
}
