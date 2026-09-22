<?php

declare(strict_types=1);

use App\Exceptions\TransferStorageException;
use App\Models\TransferFile;
use App\Providers\AppServiceProvider;
use App\Services\TransferStorage;
use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\CloudBootstrapper;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Psr\Http\Message\RequestInterface;

/** @param list<Result|Throwable|callable> $results */
function fakeArchiveStorage(array $results): MockInterface&AwsS3V3Adapter
{
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'auto',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'endpoint' => 'https://r2.example.com',
        'retries' => 0,
        'handler' => new MockHandler($results),
    ]);
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('getClient')->andReturn($client);
    $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'private-filemax']);
    $disk->shouldReceive('path')->andReturnUsing(fn (string $path): string => 'tenant/'.$path);
    config(['filemax.disk' => 'r2']);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

    return $disk;
}

it('reads remote files with HTTP streaming and the configured object prefix', function (): void {
    fakeArchiveStorage([
        function (CommandInterface $command): Result {
            expect($command->getName())->toBe('GetObject')
                ->and($command['Bucket'])->toBe('private-filemax')
                ->and($command['Key'])->toBe('tenant/files/source.txt')
                ->and($command['@http']['stream'])->toBeTrue();

            return new Result(['Body' => Utils::streamFor('source bytes')]);
        },
    ]);

    $stream = resolve(TransferStorage::class)->readStream(new TransferFile(['path' => 'files/source.txt']));
    try {
        expect(is_resource($stream))->toBeTrue()
            ->and(stream_get_contents($stream))->toBe('source bytes');
    } finally {
        fclose($stream);
    }
});

it('uploads exact multipart boundaries from a reusable RAM stream before completing', function (int $remainder): void {
    $parts = [];
    $uploads = [];
    $results = [new Result(['Uploads' => []]), new Result(['UploadId' => 'archive-upload'])];
    foreach ([16777216, ...($remainder ? [$remainder] : [])] as $index => $size) {
        $results[] = function (CommandInterface $command) use (&$parts, &$uploads, $index, $size): Result {
            expect($command->getName())->toBe('UploadPart')
                ->and($command['Key'])->toBe('tenant/archives/transfer.zip')
                ->and($command['ContentLength'])->toBe($size)
                ->and($command['PartNumber'])->toBe($index + 1);
            $body = $command['Body'];
            expect(is_resource($body))->toBeTrue()
                ->and(stream_get_meta_data($body)['uri'])->toBe('php://memory');
            $parts[] = stream_get_contents($body);
            $uploads[] = $body;

            return new Result(['ETag' => 'etag-'.($index + 1)]);
        };
    }

    $results[] = function (CommandInterface $command) use ($remainder): Result {
        expect($command->getName())->toBe('CompleteMultipartUpload')
            ->and($command['MultipartUpload']['Parts'])->toBe([
                ['PartNumber' => 1, 'ETag' => 'etag-1'],
                ...($remainder ? [['PartNumber' => 2, 'ETag' => 'etag-2']] : []),
            ]);

        return new Result();
    };
    fakeArchiveStorage($results);

    resolve(TransferStorage::class)->writeArchive('archives/transfer.zip', 16777216 + $remainder, function (callable $write) use ($remainder): void {
        $write(str_repeat("\xc3\xa9", 8388605).'a');
        $write(str_repeat('b', 5 + $remainder));
    });

    expect($parts[0])->toBe(str_repeat("\xc3\xa9", 8388605).'a'.str_repeat('b', 5));
    if ($remainder) {
        expect($parts[1])->toBe(str_repeat('b', $remainder));
    }

    foreach ($uploads as $stream) {
        expect(is_resource($stream))->toBeFalse();
    }
})->with(['exact boundary' => 0, 'final remainder' => 7]);

it('cleans up abandoned uploads across pages for only the exact archive key', function (): void {
    $aborted = [];
    fakeArchiveStorage([
        new Result([
            'Uploads' => [
                ['Key' => 'tenant/archives/transfer.zip', 'UploadId' => 'old-1'],
                ['Key' => 'tenant/archives/transfer.zip.other', 'UploadId' => 'keep'],
            ],
            'IsTruncated' => true,
            'NextKeyMarker' => 'tenant/archives/transfer.zip',
            'NextUploadIdMarker' => 'old-1',
        ]),
        function (CommandInterface $command) use (&$aborted): Result {
            $aborted[] = $command['UploadId'];

            return new Result();
        },
        function (CommandInterface $command): Result {
            expect($command['Prefix'])->toBe('tenant/archives/transfer.zip')
                ->and($command['KeyMarker'])->toBe('tenant/archives/transfer.zip')
                ->and($command['UploadIdMarker'])->toBe('old-1');

            return new Result(['Uploads' => [['Key' => 'tenant/archives/transfer.zip', 'UploadId' => 'old-2']]]);
        },
        function (CommandInterface $command) use (&$aborted): S3Exception {
            $aborted[] = $command['UploadId'];

            return new S3Exception('Already removed', new Command('AbortMultipartUpload'), ['code' => 'NoSuchUpload']);
        },
    ]);

    resolve(TransferStorage::class)->abortArchiveUploads('archives/transfer.zip');

    expect($aborted)->toBe(['old-1', 'old-2']);
});

it('aborts incomplete archives without masking a source failure when abort also fails', function (): void {
    Exceptions::fake();
    $cleanupFailure = new S3Exception('Cleanup failed', new Command('AbortMultipartUpload'));
    fakeArchiveStorage([
        new Result(['Uploads' => []]),
        new Result(['UploadId' => 'archive-upload']),
        function (CommandInterface $command) use ($cleanupFailure): S3Exception {
            expect($command->getName())->toBe('AbortMultipartUpload')
                ->and($command['UploadId'])->toBe('archive-upload');

            return $cleanupFailure;
        },
    ]);

    expect(fn () => resolve(TransferStorage::class)->writeArchive('archives/transfer.zip', 100, function (callable $write): void {
        $write('partial');
        throw new RuntimeException('Source failed');
    }))->toThrow(RuntimeException::class, 'Source failed');
    Exceptions::assertReported(fn (S3Exception $exception): bool => $exception === $cleanupFailure);
});

it('aborts archives on upload failures or invalid upload metadata', function (Result|S3Exception $uploadResult): void {
    fakeArchiveStorage([
        new Result(['Uploads' => []]),
        new Result(['UploadId' => 'archive-upload']),
        $uploadResult,
        function (CommandInterface $command): Result {
            expect($command->getName())->toBe('AbortMultipartUpload');

            return new Result();
        },
    ]);

    expect(fn () => resolve(TransferStorage::class)->writeArchive('archives/transfer.zip', 5, fn (callable $write) => $write('bytes')))
        ->toThrow($uploadResult instanceof S3Exception ? S3Exception::class : TransferStorageException::class);
})->with([
    'provider failure' => fn (): S3Exception => new S3Exception('Upload failed', new Command('UploadPart')),
    'missing ETag' => fn (): Result => new Result(),
]);

it('rejects archive bounds exceeding the object limit before opening an upload', function (): void {
    fakeArchiveStorage([]);

    expect(fn () => resolve(TransferStorage::class)->writeArchive('archives/transfer.zip', 5492189429761, fn (): null => null))
        ->toThrow(ValidationException::class);
});

it('writes local archives directly and deletes incomplete output on failure', function (): void {
    config(['filemax.disk' => 'local']);
    Storage::fake('local');
    $storage = resolve(TransferStorage::class);
    $storage->writeArchive('archives/complete.zip', 5, function (callable $write): void {
        $write('bytes');
        expect(Storage::disk('local')->get('archives/complete.zip'))->toBe('bytes');
    });

    expect(fn () => $storage->writeArchive('archives/failed.zip', 100, function (callable $write): void {
        $write('partial');
        throw new RuntimeException('Source failed');
    }))->toThrow(RuntimeException::class, 'Source failed');
    Storage::disk('local')->assertMissing('archives/failed.zip');
    expect(Storage::disk('local')->allFiles())->toBe(['archives/complete.zip']);
});

it('increases the RAM part size only when the archive bound needs more than ten thousand parts', function (): void {
    $partSizes = [];
    fakeArchiveStorage([
        new Result(['Uploads' => []]),
        new Result(['UploadId' => 'archive-upload']),
        function (CommandInterface $command) use (&$partSizes): Result {
            $partSizes[] = $command['ContentLength'];

            return new Result(['ETag' => 'etag-1']);
        },
        function (CommandInterface $command) use (&$partSizes): Result {
            $partSizes[] = $command['ContentLength'];

            return new Result(['ETag' => 'etag-2']);
        },
        new Result(),
    ]);

    resolve(TransferStorage::class)->writeArchive('archives/large.zip', 16777216 * 10000 + 1, function (callable $write): void {
        $megabyte = str_repeat('x', 1048576);
        for ($index = 0; $index < 17; $index++) {
            $write($megabyte);
        }

        $write('remainder');
    });

    expect($partSizes)->toBe([17 * 1048576, 9]);
});

it('aborts when completing a multipart archive fails', function (): void {
    fakeArchiveStorage([
        new Result(['Uploads' => []]),
        new Result(['UploadId' => 'archive-upload']),
        new Result(['ETag' => 'etag-1']),
        new S3Exception('Complete failed', new Command('CompleteMultipartUpload')),
        function (CommandInterface $command): Result {
            expect($command->getName())->toBe('AbortMultipartUpload');

            return new Result();
        },
    ]);

    expect(fn () => resolve(TransferStorage::class)->writeArchive('archives/transfer.zip', 5, fn (callable $write) => $write('bytes')))
        ->toThrow(S3Exception::class, 'Complete failed');
});

it('keeps Cloud source reads lazy when S3 supplies a response checksum', function (): void {
    $originalServer = $_SERVER;
    $requests = 0;

    try {
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([
            [
                'disk' => 'archive-source',
                'access_key_id' => 'test',
                'access_key_secret' => 'test',
                'bucket' => 'private-filemax',
                'endpoint' => 'https://r2.example.com',
                'url' => null,
            ],
        ], JSON_THROW_ON_ERROR);
        config(['filemax.disk' => 'archive-source']);
        CloudBootstrapper::configureDisks($this->app);
        config([
            'filesystems.disks.archive-source.retries' => 0,
            'filesystems.disks.archive-source.http_handler' => function (RequestInterface $request, array $options) use (&$requests): PromiseInterface {
                expect($options['stream'])->toBeTrue()
                    ->and($request->getUri()->getPath())->toBe('/files/source.txt');
                $resource = fopen('php://memory', 'w+b');
                fwrite($resource, 'source bytes');
                rewind($resource);
                $body = Utils::streamFor($resource);
                $firstRequest = $requests++ === 0;

                return Create::promiseFor(new Response(200, [
                    'Content-Length' => '12',
                    'x-amz-checksum-crc32' => $firstRequest ? base64_encode(hash('crc32b', 'source bytes', true)) : 'invalid',
                ], $firstRequest ? new NoSeekStream($body) : $body));
            },
        ]);
        new AppServiceProvider($this->app)->register();
        $storage = resolve(TransferStorage::class);
        $stream = $storage->readStream(new TransferFile(['path' => 'files/source.txt']));

        try {
            expect(ftell($stream))->toBe(0)
                ->and(stream_get_meta_data($stream)['uri'])->toBe('php://memory')
                ->and(stream_get_contents($stream))->toBe('source bytes');
        } finally {
            fclose($stream);
        }

        $disk = $storage->disk();
        expect($disk)->toBeInstanceOf(AwsS3V3Adapter::class)
            ->and($disk->getClient()->getConfig('response_checksum_validation'))->toBe('when_required');
        expect(fn () => $disk->getClient()->getObject([
            'Bucket' => 'private-filemax',
            'Key' => $disk->path('files/source.txt'),
            '@http' => ['stream' => true],
            'ChecksumMode' => 'ENABLED',
        ]))->toThrow(S3Exception::class, 'Calculated response checksum did not match');
    } finally {
        $_SERVER = $originalServer;
    }
});
