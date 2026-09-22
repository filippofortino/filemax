<?php

declare(strict_types=1);

use App\Jobs\PrepareTransferArchive;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
});

it('keeps the original archive request timestamp throughout preparation', function (): void {
    $requestedAt = now()->subMinutes(20)->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'test');

    new PrepareTransferArchive($transfer->id, $requestedAt)->handle(resolve(TransferStorage::class));

    expect($transfer->refresh()->archive_status)->toBe('ready')
        ->and($transfer->archive_progress)->toBe(100)
        ->and($transfer->archive_requested_at->format('Y-m-d H:i:s'))->toBe($requestedAt);
});

it('does not let an old job process or fail a newer archive request', function (): void {
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => now()]);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'test');
    $job = new PrepareTransferArchive($transfer->id, now()->subMinute()->format('Y-m-d H:i:s'));

    $job->handle(resolve(TransferStorage::class));
    $job->failed(new RuntimeException('An older request failed.'));

    expect($transfer->refresh()->archive_status)->toBe('pending')
        ->and($transfer->archive_progress)->toBe(0);
    Storage::disk('local')->assertMissing('archives/'.$transfer->id.'.zip');
});

it('does not regress a completed archive when a duplicate job fails', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'ready', 'archive_progress' => 100, 'archive_requested_at' => $requestedAt]);

    new PrepareTransferArchive($transfer->id, $requestedAt)->failed(new RuntimeException('Duplicate delivery.'));

    expect($transfer->refresh()->archive_status)->toBe('ready')->and($transfer->archive_progress)->toBe(100);
});

it('can wait through more than three deliveries for the byte lock and then finish', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'test');
    $lock = Cache::lock('transfer-bytes:'.$transfer->id, 3700);
    expect($lock->get())->toBeTrue();
    Queue::connection('database')->push(new PrepareTransferArchive($transfer->id, $requestedAt));

    try {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();
            $this->travel(61)->seconds();
        }

        expect(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($transfer->refresh()->archive_status)->toBe('pending');
    } finally {
        $lock->release();
    }

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();
    expect($transfer->refresh()->archive_status)->toBe('ready')->and(DB::table('jobs')->count())->toBe(0);
});

it('removes a partial archive and releases the lock when a stored file has the wrong length', function (string $contents): void {
    $transfer = Transfer::factory()->create();
    $file = TransferFile::factory()->for($transfer)->create(['size' => 4]);
    Storage::disk('local')->put($file->path, $contents);

    expect(fn () => new PrepareTransferArchive($transfer->id)->handle(resolve(TransferStorage::class)))
        ->toThrow(RuntimeException::class);
    Storage::disk('local')->assertMissing('archives/'.$transfer->id.'.zip');
    $lock = Cache::lock('transfer-bytes:'.$transfer->id, 3700);
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with(['truncated' => 'tes', 'oversized' => 'tests']);

it('fails after three actual exceptions even when earlier deliveries only waited for the lock', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    $file = TransferFile::factory()->for($transfer)->create(['size' => 4]);
    Storage::disk('local')->put($file->path, 'truncated');
    Queue::connection('database')->push(new PrepareTransferArchive($transfer->id, $requestedAt));
    $lock = Cache::lock('transfer-bytes:'.$transfer->id, 3700);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();
    } finally {
        $lock->release();
    }

    for ($exception = 1; $exception <= 3; $exception++) {
        $this->travel(61)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();
        expect(DB::table('failed_jobs')->count())->toBe($exception === 3 ? 1 : 0)
            ->and($transfer->refresh()->archive_status)->toBe($exception === 3 ? 'failed' : 'processing');
    }

    expect(DB::table('jobs')->count())->toBe(0);
});

it('stops retrying two hours after the original request without removing an active lock', function (): void {
    $requestedAt = now()->format('Y-m-d H:i:s');
    $transfer = Transfer::factory()->create(['archive_status' => 'pending', 'archive_requested_at' => $requestedAt]);
    $job = new PrepareTransferArchive($transfer->id, $requestedAt);
    $deadline = $job->retryUntil()->getTimestamp();
    Queue::connection('database')->push($job);
    $this->travel(121)->minutes();
    $lock = Cache::lock('transfer-bytes:'.$transfer->id, 3700);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();
        expect($job->retryUntil()->getTimestamp())->toBe($deadline)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and($transfer->refresh()->archive_status)->toBe('failed')
            ->and(Cache::lock('transfer-bytes:'.$transfer->id, 3700)->get())->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('dispatches the persisted request timestamp', function (): void {
    Queue::fake([PrepareTransferArchive::class]);
    $transfer = Transfer::factory()->create();

    $this->postJson(route('shared.download', $transfer->token))->assertOk();

    Queue::assertPushed(PrepareTransferArchive::class, fn (PrepareTransferArchive $job): bool => $job->requestedAt === $transfer->refresh()->archive_requested_at->format('Y-m-d H:i:s'));
});
