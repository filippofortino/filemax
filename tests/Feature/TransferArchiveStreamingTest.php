<?php

declare(strict_types=1);

use App\Jobs\PrepareTransferArchive;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\Filesystem;
use Psr\Http\Message\StreamInterface;

beforeEach(function (): void {
    Storage::fake('local');
});

/** @param callable(CommandInterface): Result $handle */
function streamingArchiveStorage(callable $handle): void
{
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'auto',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'endpoint' => 'https://r2.example.com',
        'retries' => 0,
        'handler' => fn (CommandInterface $command): PromiseInterface => Create::promiseFor($handle($command)),
    ]);
    $adapter = new FlysystemS3Adapter($client, 'private-filemax', 'tenant');
    Storage::set('streaming-archives', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, ['bucket' => 'private-filemax', 'root' => 'tenant'], $client));
    config(['filemax.disk' => 'streaming-archives']);
}

function streamingArchiveSource(int $size, string $byte = "\0"): StreamInterface
{
    $remaining = $size;
    $source = new PumpStream(function (int $length) use (&$remaining, $byte): string|false {
        if ($remaining === 0) {
            return false;
        }

        $length = min($length, $remaining);
        $remaining -= $length;

        return str_repeat($byte, $length);
    }, ['size' => $size]);

    return Utils::streamFor(StreamWrapper::getResource($source));
}

it('streams valid multipart ZIP contents including unique safe names and an empty file', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    $files = collect([
        ['original_name' => '../../report.txt', 'size' => 16777233, 'position' => 0],
        ['original_name' => 'REPORT.txt', 'size' => 7, 'position' => 1],
        ['original_name' => 'empty.txt', 'size' => 0, 'position' => 2],
    ])->map(fn (array $attributes): TransferFile => TransferFile::factory()->for($transfer)->create($attributes));
    $archive = '';
    $partSizes = [];
    $completed = false;
    $temporaryDirectories = glob(storage_path('app/archive-tmp/*'), GLOB_ONLYDIR) ?: [];

    streamingArchiveStorage(function (CommandInterface $command) use ($transfer, $files, &$archive, &$partSizes, &$completed): Result {
        expect($command['Bucket'])->toBe('private-filemax');

        switch ($command->getName()) {
            case 'ListMultipartUploads':
                return new Result(['Uploads' => []]);
            case 'CreateMultipartUpload':
                return new Result(['UploadId' => 'streamed-archive']);
            case 'GetObject':
                expect($command['@http']['stream'])->toBeTrue();
                $file = $files->first(fn (TransferFile $file): bool => $command['Key'] === 'tenant/'.$file->path);
                expect($file)->not->toBeNull();

                return new Result(['Body' => streamingArchiveSource($file->size, 'a')]);
            case 'UploadPart':
                $partSizes[] = $command['ContentLength'];
                expect(stream_get_meta_data($command['Body'])['uri'])->toBe('php://memory');
                $archive .= stream_get_contents($command['Body']);

                return new Result(['ETag' => 'etag-'.$command['PartNumber']]);
            case 'CompleteMultipartUpload':
                expect($transfer->refresh()->archive_status)->toBe('processing')
                    ->and($transfer->archive_progress)->toBeLessThan(100)
                    ->and($command['MultipartUpload']['Parts'])->toBe([
                        ['PartNumber' => 1, 'ETag' => 'etag-1'],
                        ['PartNumber' => 2, 'ETag' => 'etag-2'],
                    ]);
                $completed = true;

                return new Result;
            default:
                throw new RuntimeException('Unexpected storage command: '.$command->getName());
        }
    });

    new PrepareTransferArchive($transfer->id, $requestedAt)->handle(resolve(TransferStorage::class));

    expect($completed)->toBeTrue()
        ->and($partSizes)->toHaveCount(2)
        ->and($partSizes[0])->toBe(16777216)
        ->and($transfer->refresh()->archive_status)->toBe('ready')
        ->and($transfer->archive_progress)->toBe(100)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(glob(storage_path('app/archive-tmp/*'), GLOB_ONLYDIR) ?: [])->toBe($temporaryDirectories);

    Storage::disk('local')->put('assertion.zip', $archive);
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path('assertion.zip'), ZipArchive::CHECKCONS))->toBeTrue();
    try {
        expect($zip->numFiles)->toBe(3)
            ->and($zip->getNameIndex(0))->toBe('report.txt')
            ->and($zip->getNameIndex(1))->toBe('REPORT (2).txt')
            ->and($zip->getNameIndex(2))->toBe('empty.txt')
            ->and($zip->getFromIndex(0))->toBe(str_repeat('a', 16777233))
            ->and($zip->getFromIndex(1))->toBe('aaaaaaa')
            ->and($zip->getFromIndex(2))->toBe('');
    } finally {
        $zip->close();
    }
});

it('aborts the multipart archive when a transfer is revoked during upload', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    $file = TransferFile::factory()->for($transfer)->create(['size' => 16777217]);
    $aborted = false;

    streamingArchiveStorage(function (CommandInterface $command) use ($transfer, $file, &$aborted): Result {
        switch ($command->getName()) {
            case 'ListMultipartUploads':
                return new Result(['Uploads' => []]);
            case 'CreateMultipartUpload':
                return new Result(['UploadId' => 'cancelled-archive']);
            case 'GetObject':
                return new Result(['Body' => streamingArchiveSource($file->size)]);
            case 'UploadPart':
                $transfer->update(['revoked_at' => now()]);

                return new Result(['ETag' => 'etag-1']);
            case 'AbortMultipartUpload':
                expect($command['UploadId'])->toBe('cancelled-archive');
                $aborted = true;

                return new Result;
            default:
                throw new RuntimeException('Unexpected storage command: '.$command->getName());
        }
    });

    expect(fn () => new PrepareTransferArchive($transfer->id, $requestedAt)->handle(resolve(TransferStorage::class)))
        ->toThrow(RuntimeException::class, 'The transfer is no longer available for this archive request.');
    expect($aborted)->toBeTrue()
        ->and($transfer->refresh()->archive_status)->not->toBe('ready')
        ->and($transfer->archive_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('deletes a completed object when a newer archive request replaced its request during completion', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    TransferFile::factory()->for($transfer)->create(['size' => 5]);
    $deleted = false;
    $nextRequest = now()->addSecond()->format('Y-m-d H:i:s');

    streamingArchiveStorage(function (CommandInterface $command) use ($transfer, $nextRequest, &$deleted): Result {
        switch ($command->getName()) {
            case 'ListMultipartUploads':
                return new Result(['Uploads' => []]);
            case 'CreateMultipartUpload':
                return new Result(['UploadId' => 'outdated-archive']);
            case 'GetObject':
                return new Result(['Body' => streamingArchiveSource(5)]);
            case 'UploadPart':
                return new Result(['ETag' => 'etag-1']);
            case 'CompleteMultipartUpload':
                $transfer->refresh()->update(['archive_requested_at' => $nextRequest, 'archive_status' => 'pending', 'archive_progress' => 0]);

                return new Result;
            case 'DeleteObject':
                expect($command['Key'])->toBe('tenant/archives/'.$transfer->id.'.zip');
                $deleted = true;

                return new Result;
            default:
                throw new RuntimeException('Unexpected storage command: '.$command->getName());
        }
    });

    new PrepareTransferArchive($transfer->id, $requestedAt)->handle(resolve(TransferStorage::class));

    expect($deleted)->toBeTrue()
        ->and($transfer->refresh()->archive_status)->toBe('pending')
        ->and($transfer->archive_progress)->toBe(0)
        ->and($transfer->archive_requested_at->format('Y-m-d H:i:s'))->toBe($nextRequest)
        ->and($transfer->archive_path)->toBeNull();
});

it('streams a six GiB ZIP64 archive with bounded memory and no local staging', function (): void {
    $size = 6 * 1024 * 1024 * 1024;
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'large.bin', 'size' => $size]);
    $uploadedBytes = 0;
    $partCount = 0;
    $tail = '';
    $completed = false;
    $temporaryDirectories = glob(storage_path('app/archive-tmp/*'), GLOB_ONLYDIR) ?: [];

    streamingArchiveStorage(function (CommandInterface $command) use ($size, &$uploadedBytes, &$partCount, &$tail, &$completed): Result {
        switch ($command->getName()) {
            case 'ListMultipartUploads':
                return new Result(['Uploads' => []]);
            case 'CreateMultipartUpload':
                return new Result(['UploadId' => 'large-archive']);
            case 'GetObject':
                expect($command['@http']['stream'])->toBeTrue();

                return new Result(['Body' => streamingArchiveSource($size)]);
            case 'UploadPart':
                $length = $command['ContentLength'];
                $uploadedBytes += $length;
                $partCount++;
                expect($command['PartNumber'])->toBe($partCount)
                    ->and($length)->toBeLessThanOrEqual(16777216)
                    ->and(stream_get_meta_data($command['Body'])['uri'])->toBe('php://memory');
                fseek($command['Body'], max(0, $length - 4096));
                $tail = mb_substr($tail.stream_get_contents($command['Body']), -8192, null, '8bit');

                return new Result(['ETag' => 'etag-'.$partCount]);
            case 'CompleteMultipartUpload':
                expect($command['MultipartUpload']['Parts'])->toHaveCount($partCount);
                $completed = true;

                return new Result;
            default:
                throw new RuntimeException('Unexpected storage command: '.$command->getName());
        }
    });

    memory_reset_peak_usage();
    $baselineMemory = memory_get_usage(true);
    new PrepareTransferArchive($transfer->id, $requestedAt)->handle(resolve(TransferStorage::class));
    $peakGrowth = memory_get_peak_usage(true) - $baselineMemory;

    expect($completed)->toBeTrue()
        ->and($partCount)->toBe(385)
        ->and($uploadedBytes)->toBeGreaterThan($size)
        ->and($uploadedBytes)->toBeLessThan($size + 4096)
        ->and($peakGrowth)->toBeLessThan(128 * 1024 * 1024)
        ->and($transfer->refresh()->archive_status)->toBe('ready')
        ->and($transfer->archive_progress)->toBe(100)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(glob(storage_path('app/archive-tmp/*'), GLOB_ONLYDIR) ?: [])->toBe($temporaryDirectories);

    $centralOffset = mb_strpos($tail, "PK\x01\x02", encoding: '8bit');
    $endOffset = mb_strpos($tail, "PK\x06\x06", encoding: '8bit');
    expect($centralOffset)->not->toBeFalse()
        ->and($endOffset)->not->toBeFalse()
        ->and($tail)->toContain("PK\x06\x07")
        ->and(mb_substr($tail, -22, 4, '8bit'))->toBe("PK\x05\x06");
    $central = mb_substr($tail, $centralOffset, null, '8bit');
    $nameLength = unpack('v', mb_substr($central, 28, 2, '8bit'))[1];
    $extra = mb_substr($central, 46 + $nameLength, null, '8bit');
    expect(unpack('Vcompressed/Vuncompressed', mb_substr($central, 20, 8, '8bit')))->toBe(['compressed' => 0xFFFFFFFF, 'uncompressed' => 0xFFFFFFFF])
        ->and(unpack('v', mb_substr($extra, 0, 2, '8bit'))[1])->toBe(1)
        ->and(unpack('P', mb_substr($extra, 4, 8, '8bit'))[1])->toBe($size)
        ->and(unpack('P', mb_substr($extra, 12, 8, '8bit'))[1])->toBe($size)
        ->and(unpack('P', mb_substr($tail, $endOffset + 32, 8, '8bit'))[1])->toBe(1)
        ->and(unpack('P', mb_substr($tail, $endOffset + 48, 8, '8bit'))[1])->toBeGreaterThan($size);
})->group('large-archives');
