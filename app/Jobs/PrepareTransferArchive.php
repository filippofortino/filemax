<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

#[FailOnTimeout]
#[Timeout(3600)]
#[Tries(3)]
#[UniqueFor(3700)]
final class PrepareTransferArchive implements ShouldBeUnique, ShouldQueue
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

        $directory = storage_path('app/archive-tmp/'.$this->transferId.'-'.Str::uuid());
        $zip = new ZipArchive;
        $zipOpen = false;

        try {
            $transfer = Transfer::query()->find($this->transferId);

            if (! $transfer || ! $transfer->isAvailable()) {
                return;
            }

            if ($transfer->archive_status === 'ready' && $transfer->archive_path && $storage->disk()->exists($transfer->archive_path)) {
                return;
            }

            $transfer->forceFill(['archive_status' => 'processing', 'archive_progress' => 0, 'archive_requested_at' => now()])->save();
            File::ensureDirectoryExists($directory, 0700);
            $totalSize = (int) $transfer->files()->sum('size');
            $freeSpace = disk_free_space($directory);

            throw_if($freeSpace !== false && $freeSpace < ($totalSize * 2) + 16_777_216, RuntimeException::class, 'Not enough temporary disk space to prepare this archive. Individual downloads remain available.');

            $zipPath = $directory.'/transfer.zip';

            throw_if($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true, RuntimeException::class, 'Could not create the transfer archive.');

            $zipOpen = true;

            $names = [];
            $copied = 0;
            $progress = -1;

            foreach ($transfer->files()->orderBy('position')->cursor() as $file) {
                $temporaryPath = $directory.'/'.$file->id;
                $this->copyFile($storage, $file, $temporaryPath, function (int $bytes) use ($transfer, $totalSize, &$copied, &$progress): void {
                    $copied += $bytes;
                    $next = (int) floor($copied / max($totalSize, 1) * 85);

                    if ($next > $progress) {
                        $progress = $next;
                        $transfer->forceFill(['archive_progress' => min(85, $progress)])->save();
                    }
                });

                $name = $file->safeName();
                $stem = pathinfo($name, PATHINFO_FILENAME);
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $suffix = 1;

                while (isset($names[mb_strtolower($name)])) {
                    $name = $stem.' ('.(++$suffix).')'.($extension !== '' ? '.'.$extension : '');
                }

                $names[mb_strtolower($name)] = true;

                throw_if(! $zip->addFile($temporaryPath, $name) || ! $zip->setCompressionName($name, ZipArchive::CM_STORE), RuntimeException::class, 'Could not add a file to the archive.');
            }

            $zip->registerProgressCallback(0.05, function (float $progress) use ($transfer): void {
                $transfer->forceFill(['archive_progress' => 85 + (int) floor($progress * 10)])->save();
            });

            $closed = $zip->close();
            $zipOpen = false;

            throw_unless($closed, RuntimeException::class, 'Could not finish the transfer archive.');

            if (! $transfer->refresh()->isAvailable()) {
                return;
            }

            $path = 'archives/'.$transfer->id.'.zip';
            $stream = fopen($zipPath, 'rb');

            try {
                throw_if($stream === false || ! $storage->disk()->put($path, $stream, ['visibility' => 'private', 'ContentType' => 'application/zip']), RuntimeException::class, 'Could not store the transfer archive.');
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $transfer->forceFill(['archive_status' => 'ready', 'archive_path' => $path, 'archive_progress' => 100])->save();
        } finally {
            if ($zipOpen) {
                $zip->close();
            }

            File::deleteDirectory($directory);
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Transfer::query()->whereKey($this->transferId)->update(['archive_status' => 'failed', 'archive_progress' => 0]);
    }

    private function copyFile(TransferStorage $storage, TransferFile $file, string $destination, callable $onProgress): void
    {
        $input = $storage->disk()->readStream($file->path);
        $output = fopen($destination, 'wb');

        try {
            throw_if(! is_resource($input) || ! is_resource($output), RuntimeException::class, 'Could not read a transfer file.');

            $copied = 0;

            while (! feof($input)) {
                $bytes = stream_copy_to_stream($input, $output, 8_388_608);

                throw_if($bytes === false || ($bytes === 0 && ! feof($input)), RuntimeException::class, 'Could not copy a transfer file.');

                $copied += $bytes;
                $onProgress($bytes);
            }

            throw_if($copied !== $file->size, RuntimeException::class, 'The stored file size does not match the transfer.');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}
