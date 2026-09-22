<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use App\Services\TransferStorage;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
});

function transferUploadPayload(array $overrides = []): array
{
    return array_replace(['visibility' => 'public', 'team_ids' => [], 'expires_in_days' => 7, 'files' => [['name' => 'hello.txt', 'size' => 5]]], $overrides);
}

function putTransferPart(string $transferId, string $fileId, string $content, int $part = 1): TestResponse
{
    return test()->call('PUT', route('transfers.uploads.upload', [$transferId, $fileId, $part]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $content);
}

it('uploads privately and publishes idempotently using actual bytes', function (): void {
    $response = $this->postJson(route('transfers.store'), transferUploadPayload())->assertCreated()->assertJsonPath('transfer.url', null)->assertJsonPath('transfer.token', null);
    $id = $response->json('transfer.id');
    $fileId = $response->json('transfer.files.0.id');
    $this->postJson(route('transfers.uploads.sign', [$id, $fileId, 1]))->assertOk()->assertJsonPath('completed', false);
    putTransferPart($id, $fileId, 'hello')->assertOk();
    $this->postJson(route('transfers.uploads.sign', [$id, $fileId, 1]))->assertJsonPath('completed', true);
    $this->postJson(route('transfers.uploads.complete', [$id, $fileId]))->assertOk()->assertJsonPath('file.status', 'ready');
    $first = $this->postJson(route('transfers.uploads.finalize', $id))->assertOk()->assertJsonPath('transfer.title', 'hello.txt');
    $second = $this->postJson(route('transfers.uploads.finalize', $id))->assertOk();
    expect($second->json('url'))->toBe($first->json('url'));
    expect($second->json('transfer.expires_at'))->toBe($first->json('transfer.expires_at'));
    expect(Storage::disk('local')->get(TransferFile::query()->findOrFail($fileId)->path))->toBe('hello');
    expect(Transfer::query()->findOrFail($id)->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
    $this->postJson(route('transfers.uploads.sign', [$id, $fileId, 1]))->assertConflict();
    putTransferPart($id, $fileId, 'retry')->assertConflict();
    $this->postJson(route('transfers.uploads.complete', [$id, $fileId]))->assertConflict();
    $this->deleteJson(route('transfers.uploads.remove', [$id, $fileId]))->assertConflict();
    expect(Storage::disk('local')->get(TransferFile::query()->findOrFail($fileId)->path))->toBe('hello');
});

it('keeps completed files and valid parts after a failed middle file', function (): void {
    $response = $this->postJson(route('transfers.store'), transferUploadPayload(['files' => [['name' => 'first.txt', 'size' => 5], ['name' => 'middle.txt', 'size' => 5], ['name' => 'last.txt', 'size' => 4]]]))->assertCreated();
    $id = $response->json('transfer.id');
    $files = $response->json('transfer.files');
    putTransferPart($id, $files[0]['id'], 'first')->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$id, $files[0]['id']]))->assertOk();
    putTransferPart($id, $files[1]['id'], 'bad')->assertUnprocessable();
    putTransferPart($id, $files[2]['id'], 'last')->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$id, $files[2]['id']]))->assertOk();
    $this->postJson(route('transfers.uploads.finalize', $id))->assertUnprocessable();
    putTransferPart($id, $files[1]['id'], 'retry')->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$id, $files[1]['id']]))->assertOk();
    $this->postJson(route('transfers.uploads.finalize', $id))->assertOk();
    expect(Transfer::query()->findOrFail($id)->files()->count())->toBe(3);
    expect(Storage::disk('local')->get(TransferFile::query()->findOrFail($files[0]['id'])->path))->toBe('first');
});

it('preserves completed chunks on a partial file retry', function (): void {
    $transfer = Transfer::factory()->uploading()->create(['user_id' => auth()->id()]);
    $file = TransferFile::factory()->create(['transfer_id' => $transfer->id, 'size' => 8, 'part_size' => 4, 'status' => 'uploading']);
    putTransferPart($transfer->id, $file->id, 'abcd')->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertUnprocessable();
    $this->postJson(route('transfers.uploads.sign', [$transfer, $file, 1]))->assertJsonPath('completed', true);
    putTransferPart($transfer->id, $file->id, 'efgh', 2)->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertOk();
    expect(Storage::disk('local')->get($file->path))->toBe('abcdefgh');
});

it('allows removing failed files but never publishes an empty transfer', function (): void {
    $response = $this->postJson(route('transfers.store'), transferUploadPayload())->assertCreated();
    $id = $response->json('transfer.id');
    $this->deleteJson(route('transfers.uploads.remove', [$id, $response->json('transfer.files.0.id')]))->assertOk();
    $this->postJson(route('transfers.uploads.finalize', $id))->assertUnprocessable();
    expect(Transfer::query()->findOrFail($id)->status)->toBe('uploading');
});

it('revalidates memberships when publishing without losing completed bytes', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    auth()->user()->teams()->attach([$team->id, $otherTeam->id]);
    $response = $this->postJson(route('transfers.store'), transferUploadPayload(['visibility' => 'teams', 'team_ids' => [$team->id]]))->assertCreated();
    $id = $response->json('transfer.id');
    $file = TransferFile::query()->findOrFail($response->json('transfer.files.0.id'));
    putTransferPart($id, $file->id, 'hello')->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$id, $file]))->assertOk();
    auth()->user()->teams()->detach($team);
    $this->postJson(route('transfers.uploads.finalize', $id))->assertUnprocessable()->assertJsonValidationErrors('team_ids');
    Storage::disk('local')->assertExists($file->path);
    $this->patchJson(route('transfers.update', $id), ['team_ids' => [$otherTeam->id]])->assertOk();
    $this->postJson(route('transfers.uploads.finalize', $id))->assertOk();
});

it('blocks upload changes and publication after cancellation', function (): void {
    Queue::fake();
    $response = $this->postJson(route('transfers.store'), transferUploadPayload())->assertCreated();
    $id = $response->json('transfer.id');
    $fileId = $response->json('transfer.files.0.id');
    putTransferPart($id, $fileId, 'hello')->assertOk();
    $this->deleteJson(route('transfers.destroy', $id))->assertOk();
    $this->postJson(route('transfers.uploads.sign', [$id, $fileId, 1]))->assertGone();
    putTransferPart($id, $fileId, 'retry')->assertGone();
    $this->postJson(route('transfers.uploads.complete', [$id, $fileId]))->assertGone();
    $this->deleteJson(route('transfers.uploads.remove', [$id, $fileId]))->assertGone();
    $this->postJson(route('transfers.uploads.finalize', $id))->assertGone();
});

it('requires ownership for every upload resource', function (): void {
    $transfer = Transfer::factory()->uploading()->create();
    $file = TransferFile::factory()->for($transfer)->create(['status' => 'uploading']);

    $this->postJson(route('transfers.uploads.sign', [$transfer, $file, 1]))->assertForbidden();
    putTransferPart($transfer->id, $file->id, 'test')->assertForbidden();
    $this->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertForbidden();
    $this->deleteJson(route('transfers.uploads.remove', [$transfer, $file]))->assertForbidden();
    $this->postJson(route('transfers.uploads.finalize', $transfer))->assertForbidden();

    expect($file->fresh())->not->toBeNull();
    expect($transfer->refresh()->status)->toBe('uploading');
});

it('rejects foreign file identifiers and forged byte sizes', function (): void {
    $response = $this->postJson(route('transfers.store'), transferUploadPayload())->assertCreated();
    $id = $response->json('transfer.id');
    $foreign = TransferFile::factory()->create();
    $this->postJson(route('transfers.uploads.sign', [$id, $foreign, 1]))->assertNotFound();
    putTransferPart($id, $foreign->id, 'test')->assertNotFound();
    $this->postJson(route('transfers.uploads.complete', [$id, $foreign]))->assertNotFound();
    $this->deleteJson(route('transfers.uploads.remove', [$id, $foreign]))->assertNotFound();
    putTransferPart($id, $response->json('transfer.files.0.id'), 'too many bytes')->assertUnprocessable();
});

it('uses unique private keys for duplicate unsafe names and accepts sizes beyond 32 bits', function (): void {
    $response = $this->postJson(route('transfers.store'), transferUploadPayload(['files' => [['name' => '../../same.txt', 'size' => 5], ['name' => '../../same.txt', 'size' => 4294967296]]]))->assertCreated();
    $files = Transfer::query()->findOrFail($response->json('transfer.id'))->files;
    expect($files[0]->path)->not->toBe($files[1]->path);
    expect($files[0]->safeName())->toBe('same.txt');
    expect($files[1]->size)->toBe(4294967296);
    expect($response->json('transfer.files.0'))->not->toHaveKey('path');
});

it('validates visibility expiry and authorized distinct teams', function (array $invalid): void {
    $this->postJson(route('transfers.store'), transferUploadPayload($invalid))->assertUnprocessable();
})->with([
    'once downloaded' => [['expires_in_days' => 'once']],
    'unknown visibility' => [['visibility' => 'private']],
    'empty restricted' => [['visibility' => 'teams']],
    'public with teams' => [['team_ids' => ['00000000-0000-4000-8000-000000000001']]],
    'foreign team' => [['visibility' => 'teams', 'team_ids' => ['00000000-0000-4000-8000-000000000001']]],
    'negative size' => [['files' => [['name' => 'file', 'size' => -1]]]],
    'empty file list' => [['files' => []]],
]);

it('supports each timed expiry and zero byte files', function (int $days): void {
    $response = $this->postJson(route('transfers.store'), transferUploadPayload(['expires_in_days' => $days, 'files' => [['name' => 'empty.txt', 'size' => 0]]]))->assertCreated();
    $id = $response->json('transfer.id');
    $fileId = $response->json('transfer.files.0.id');
    putTransferPart($id, $fileId, '')->assertOk();
    $this->postJson(route('transfers.uploads.complete', [$id, $fileId]))->assertOk();
    $this->postJson(route('transfers.uploads.finalize', $id))->assertOk();
    expect(Transfer::query()->findOrFail($id)->expires_at->toDateTimeString())->toBe(now()->addDays($days)->toDateTimeString());
})->with([1, 7, 14, 30]);

function fakeRemoteTransferStorage(array $results): MockInterface&AwsS3V3Adapter
{
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'auto',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'endpoint' => 'https://r2.example.com',
        'handler' => new MockHandler($results),
    ]);
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('getClient')->andReturn($client);
    $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'private-filemax']);
    config(['filemax.disk' => 'r2']);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

    return $disk;
}

it('signs direct multipart requests and verifies provider-listed bytes before completion', function (): void {
    $transfer = Transfer::factory()->uploading()->create(['user_id' => auth()->id()]);
    $file = TransferFile::factory()->for($transfer)->create(['status' => 'uploading', 'size' => 5]);
    $disk = fakeRemoteTransferStorage([
        new Result(['UploadId' => 'upload-1']),
        new Result(['Parts' => [], 'IsTruncated' => false]),
        new Result(['Parts' => [['PartNumber' => 1, 'Size' => 5, 'ETag' => 'etag-1']], 'IsTruncated' => false]),
        new Result(),
    ]);
    $signed = $this->postJson(route('transfers.uploads.sign', [$transfer, $file, 1]))->assertOk()->assertJsonPath('completed', false);
    expect($signed->json('url'))->toContain('r2.example.com')->toContain('X-Amz-Signature');
    expect($file->refresh()->upload_id)->toBe('upload-1');
    $disk->shouldReceive('exists')->with($file->path)->andReturn(false, true);
    $disk->shouldReceive('size')->with($file->path)->andReturn(5);
    $disk->shouldReceive('mimeType')->with($file->path)->andReturn('application/octet-stream');
    $disk->shouldReceive('deleteDirectory')->andReturn(true);
    $this->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertOk()->assertJsonPath('file.status', 'ready');
    expect($file->refresh()->upload_id)->toBeNull();
});

it('keeps the upload identifier after a provider error so retry does not orphan parts', function (): void {
    $transfer = Transfer::factory()->uploading()->create(['user_id' => auth()->id()]);
    $file = TransferFile::factory()->for($transfer)->create(['status' => 'uploading', 'size' => 5]);
    fakeRemoteTransferStorage([
        new Result(['UploadId' => 'upload-retry']),
        new S3Exception('Private provider diagnostics', new Command('ListParts')),
        new Result(['Parts' => [['PartNumber' => 1, 'Size' => 5, 'ETag' => 'etag-1']], 'IsTruncated' => false]),
    ]);
    $this->postJson(route('transfers.uploads.sign', [$transfer, $file, 1]))->assertStatus(503)->assertDontSee('Private provider diagnostics');
    expect($file->refresh()->upload_id)->toBe('upload-retry');
    $this->postJson(route('transfers.uploads.sign', [$transfer, $file, 1]))->assertOk()->assertJsonPath('completed', true);
});

it('rejects wrong provider part sizes and respects provider part limits', function (): void {
    $transfer = Transfer::factory()->uploading()->create(['user_id' => auth()->id()]);
    $file = TransferFile::factory()->for($transfer)->create(['status' => 'uploading', 'size' => 5, 'upload_id' => 'upload-1']);
    $disk = fakeRemoteTransferStorage([new Result(['Parts' => [['PartNumber' => 1, 'Size' => 4, 'ETag' => 'wrong-size']], 'IsTruncated' => false])]);
    $disk->shouldReceive('exists')->with($file->path)->andReturn(false);
    $this->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertUnprocessable();
    $storage = resolve(TransferStorage::class);
    $size = 5492189429760;
    expect((int) ceil($size / $storage->partSize($size)))->toBeLessThanOrEqual(10000);
    expect(fn () => $storage->partSize($size + 1))->toThrow(ValidationException::class);
});
