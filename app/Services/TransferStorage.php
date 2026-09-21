<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TransferStorageException;
use App\Models\Transfer;
use App\Models\TransferFile;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class TransferStorage
{
    public function isRemote(): bool
    {
        return $this->disk() instanceof AwsS3V3Adapter;
    }

    /**
     * @template T
     *
     * @param  callable(Transfer): T  $callback
     * @return T
     */
    public function withTransferLock(Transfer $transfer, callable $callback): mixed
    {
        $lock = Cache::lock('transfer-bytes:'.$transfer->id, 3700);
        $lock->block(10);
        try {
            return DB::transaction(fn () => $callback(Transfer::query()->lockForUpdate()->findOrFail($transfer->id)));
        } finally {
            $lock->release();
        }
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(Config::string('filemax.disk', 'local'));
    }

    public function partSize(int $size): int
    {
        if ($this->disk() instanceof AwsS3V3Adapter && $size > 5492189429760) {
            throw ValidationException::withMessages(['files' => 'This file exceeds the storage provider’s maximum object size (just under 5 TiB).']);
        }

        return $this->disk() instanceof AwsS3V3Adapter
            ? max(5242880, (int) ceil($size / 10000 / 1048576) * 1048576)
            : 5242880;
    }

    /** @return array{url: string, method: string, headers: array<string, string>, completed: bool} */
    public function sign(TransferFile $file, int $part): array
    {
        $this->validatePart($file, $part);
        $completed = $file->status === 'ready';

        if (! $this->disk() instanceof AwsS3V3Adapter) {
            $path = $this->partPath($file, $part);

            return ['url' => route('transfers.uploads.upload', [$file->transfer_id, $file->id, $part]), 'method' => 'PUT', 'headers' => ['Content-Type' => 'application/octet-stream'], 'completed' => $completed || ($this->disk()->exists($path) && $this->disk()->size($path) === $file->partBytes($part))];
        }

        if ($completed) {
            return ['url' => '', 'method' => 'PUT', 'headers' => [], 'completed' => true];
        }

        if ($file->upload_id === null) {
            $result = $this->client()->createMultipartUpload($this->objectArguments($file) + ['ContentType' => 'application/octet-stream']);
            $uploadId = $result['UploadId'];
            throw_unless(is_string($uploadId), TransferStorageException::class, 'Storage did not return an upload identifier.');

            $file->update(['upload_id' => $uploadId]);
        }

        try {
            $existing = $this->remoteParts($file, $part - 1, 1)['parts'][0] ?? null;
        } catch (S3Exception $s3Exception) {
            if ($s3Exception->getAwsErrorCode() === 'NoSuchUpload') {
                $file->update(['upload_id' => null]);
            }

            throw $s3Exception;
        }

        $completed = $existing !== null && $existing['PartNumber'] === $part && $existing['Size'] === $file->partBytes($part);

        $command = $this->client()->getCommand('UploadPart', $this->objectArguments($file) + ['UploadId' => $file->upload_id, 'PartNumber' => $part, 'ContentLength' => $file->partBytes($part)]);
        $request = $this->client()->createPresignedRequest($command, '+10 minutes');

        return ['url' => (string) $request->getUri(), 'method' => 'PUT', 'headers' => ['Content-Type' => 'application/octet-stream'], 'completed' => $completed];
    }

    /** @param resource $stream */
    public function uploadPart(TransferFile $file, int $part, mixed $stream): void
    {
        abort_if($this->disk() instanceof AwsS3V3Adapter, 404);
        $this->validatePart($file, $part);
        $path = $this->partPath($file, $part);
        $temporary = $path.'.incoming';
        $buffer = tmpfile();
        throw_if($buffer === false, TransferStorageException::class, 'Not enough temporary storage to receive this part.');

        try {
            if (stream_copy_to_stream($stream, $buffer, $file->partBytes($part) + 1) !== $file->partBytes($part)) {
                throw ValidationException::withMessages(['file' => 'The uploaded part has an unexpected size. Retry this file.']);
            }

            rewind($buffer);
            throw_unless($this->disk()->writeStream($temporary, $buffer), TransferStorageException::class, 'Could not save the uploaded part.');

            throw_unless($this->disk()->move($temporary, $path), TransferStorageException::class, 'Could not finish saving the uploaded part.');
        } finally {
            fclose($buffer);
            $this->disk()->delete($temporary);
        }
    }

    public function complete(TransferFile $file): void
    {
        if ($file->status === 'ready') {
            return;
        }

        if (! $this->isRemote() && $this->disk()->exists($file->path) && $this->disk()->size($file->path) !== $file->size) {
            $this->disk()->delete($file->path);
        }

        if (! $this->disk()->exists($file->path)) {
            if ($this->disk() instanceof AwsS3V3Adapter) {
                $this->completeRemote($file);
            } else {
                $this->completeLocal($file);
            }
        }

        $this->verify($file);
        $file->update(['status' => 'ready', 'upload_id' => null, 'mime_type' => $this->disk()->mimeType($file->path) ?: 'application/octet-stream']);
        $this->disk()->deleteDirectory($this->partDirectory($file));
    }

    public function verify(TransferFile $file): void
    {
        if (! $this->disk()->exists($file->path) || $this->disk()->size($file->path) !== $file->size) {
            throw ValidationException::withMessages(['files' => 'One or more files are incomplete. Retry them before creating your link.']);
        }
    }

    public function abort(TransferFile $file): void
    {
        if ($file->upload_id !== null && $this->disk() instanceof AwsS3V3Adapter) {
            try {
                $this->client()->abortMultipartUpload($this->objectArguments($file) + ['UploadId' => $file->upload_id]);
            } catch (S3Exception $exception) {
                throw_if($exception->getAwsErrorCode() !== 'NoSuchUpload', $exception);
            }

            $file->update(['upload_id' => null]);
        }

        throw_unless($this->disk()->deleteDirectory($this->partDirectory($file)), TransferStorageException::class, 'Could not remove upload parts.');
    }

    private function completeLocal(TransferFile $file): void
    {
        $temporary = tmpfile();
        throw_if($temporary === false, TransferStorageException::class, 'Not enough temporary storage to finish this file.');

        try {
            for ($part = 1; $part <= $file->partCount(); $part++) {
                $path = $this->partPath($file, $part);
                if (! $this->disk()->exists($path) || $this->disk()->size($path) !== $file->partBytes($part)) {
                    throw ValidationException::withMessages(['file' => 'A part is missing. Retry this file to continue.']);
                }

                $stream = $this->disk()->readStream($path);
                throw_unless(is_resource($stream), TransferStorageException::class, 'Could not read an uploaded part.');

                try {
                    throw_if(stream_copy_to_stream($stream, $temporary) !== $file->partBytes($part), TransferStorageException::class, 'Could not assemble the uploaded file.');
                } finally {
                    fclose($stream);
                }
            }

            rewind($temporary);
            if (! $this->disk()->writeStream($file->path, $temporary)) {
                $this->disk()->delete($file->path);
                throw new TransferStorageException('Could not save the uploaded file.');
            }
        } finally {
            fclose($temporary);
        }
    }

    private function completeRemote(TransferFile $file): void
    {
        if ($file->upload_id === null) {
            throw ValidationException::withMessages(['file' => 'Upload this file before completing it.']);
        }

        $parts = [];
        $marker = 0;
        do {
            $result = $this->remoteParts($file, $marker);
            foreach ($result['parts'] as $part) {
                $number = $part['PartNumber'];
                if ($number !== count($parts) + 1 || $part['Size'] !== $file->partBytes($number)) {
                    throw ValidationException::withMessages(['file' => 'An uploaded part is incomplete. Retry this file.']);
                }

                $parts[] = ['PartNumber' => $number, 'ETag' => $part['ETag']];
            }

            $marker = $result['next'];
        } while ($result['more']);

        if (count($parts) !== $file->partCount()) {
            throw ValidationException::withMessages(['file' => 'A part is missing. Retry this file to continue.']);
        }

        $this->client()->completeMultipartUpload($this->objectArguments($file) + ['UploadId' => $file->upload_id, 'MultipartUpload' => ['Parts' => $parts]]);
    }

    private function validatePart(TransferFile $file, int $part): void
    {
        abort_unless($part >= 1 && $part <= $file->partCount(), 422, 'Invalid upload part.');
    }

    private function partDirectory(TransferFile $file): string
    {
        return 'uploads/'.$file->transfer_id.'/'.$file->id;
    }

    private function partPath(TransferFile $file, int $part): string
    {
        return $this->partDirectory($file).'/'.$part;
    }

    /** @return array{parts: list<array{PartNumber: int, Size: int, ETag: string}>, next: int, more: bool} */
    private function remoteParts(TransferFile $file, int $marker = 0, int $maximum = 1000): array
    {
        throw_if($file->upload_id === null, TransferStorageException::class, 'This file has no active upload.');

        $result = $this->client()->listParts($this->objectArguments($file) + ['UploadId' => $file->upload_id, 'PartNumberMarker' => $marker, 'MaxParts' => $maximum]);
        $rawParts = $result['Parts'] ?? [];
        throw_unless(is_array($rawParts), TransferStorageException::class, 'Storage returned invalid upload parts.');

        $parts = [];
        foreach ($rawParts as $part) {
            throw_if(! is_array($part) || ! is_int($part['PartNumber'] ?? null) || ! is_int($part['Size'] ?? null) || ! is_string($part['ETag'] ?? null), TransferStorageException::class, 'Storage returned an invalid upload part.');

            $parts[] = ['PartNumber' => $part['PartNumber'], 'Size' => $part['Size'], 'ETag' => $part['ETag']];
        }

        $next = $result['NextPartNumberMarker'] ?? 0;

        return ['parts' => $parts, 'next' => is_int($next) ? $next : 0, 'more' => $result['IsTruncated'] === true];
    }

    private function client(): S3ClientInterface
    {
        $disk = $this->disk();
        throw_unless($disk instanceof AwsS3V3Adapter, TransferStorageException::class, 'The selected disk does not support multipart uploads.');

        return $disk->getClient();
    }

    /** @return array{Bucket: string, Key: string} */
    private function objectArguments(TransferFile $file): array
    {
        $bucket = $this->disk()->getConfig()['bucket'];
        throw_unless(is_string($bucket), TransferStorageException::class, 'Configure a private storage bucket before uploading.');

        return ['Bucket' => $bucket, 'Key' => $file->path];
    }
}
