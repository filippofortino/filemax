<?php

declare(strict_types=1);

use App\Jobs\PurgeTransfer;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
});

it('preserves originals for all expiry periods until the exact 30 day purge boundary', function (int $duration): void {
    $expires = now()->addDays($duration);
    $transfer = Transfer::factory()->create(['expires_in_days' => $duration, 'expires_at' => $expires, 'download_count' => 7]);
    $file = TransferFile::factory()->for($transfer)->create(['download_count' => 3]);
    Storage::disk('local')->put($file->path, 'test');
    $job = new PurgeTransfer($transfer->id);

    $this->travelTo($expires->copy()->addDays(30)->subSecond());
    $job->handle(resolve(TransferStorage::class));
    Storage::disk('local')->assertExists($file->path);
    expect($transfer->refresh()->purged_at)->toBeNull()->and($transfer->isAvailable())->toBeFalse();
    $this->travel(1)->second();
    $job->handle(resolve(TransferStorage::class));
    $job->handle(resolve(TransferStorage::class));
    Storage::disk('local')->assertMissing($file->path);
    expect($transfer->refresh()->purged_at)->not->toBeNull()
        ->and($transfer->download_count)->toBe(7)
        ->and($file->refresh()->download_count)->toBe(3);
})->with([1, 7, 14, 30]);

it('rechecks a changed expiry before executing an old cleanup job', function (): void {
    $transfer = Transfer::factory()->create(['expires_at' => now()->subDays(31)]);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'test');
    $job = new PurgeTransfer($transfer->id);
    $transfer->update(['expires_at' => now()->addDay()]);

    $job->handle(resolve(TransferStorage::class));
    Storage::disk('local')->assertExists($file->path);
    expect($transfer->refresh()->purged_at)->toBeNull();
});

it('purges deleted transfers and abandoned uploads while keeping recent drafts', function (): void {
    $deleted = Transfer::factory()->create(['revoked_at' => now(), 'archive_path' => 'archives/deleted.zip', 'archive_status' => 'ready']);
    $abandoned = Transfer::factory()->uploading()->create(['updated_at' => now()->subDays(2)]);
    $recent = Transfer::factory()->uploading()->create();
    foreach ([$deleted, $abandoned, $recent] as $transfer) {
        $file = TransferFile::factory()->for($transfer)->create();
        Storage::disk('local')->put($file->path, 'test');
        Storage::disk('local')->put('uploads/'.$transfer->id.'/'.$file->id.'/1', 'part');
    }

    Storage::disk('local')->put('archives/deleted.zip', 'archive');

    $this->artisan('filemax:cleanup')->assertSuccessful();
    expect($deleted->refresh()->purged_at)->not->toBeNull()
        ->and($abandoned->refresh()->purged_at)->not->toBeNull()
        ->and($recent->refresh()->purged_at)->toBeNull();
    Storage::disk('local')->assertMissing(['archives/deleted.zip', $deleted->files->first()->path, $abandoned->files->first()->path]);
    Storage::disk('local')->assertExists($recent->files->first()->path);
});

it('queues only eligible transfers even when the scheduler runs late', function (): void {
    Queue::fake([PurgeTransfer::class]);
    Transfer::factory()->create(['expires_at' => now()->subDays(90)]);
    Transfer::factory()->create(['expires_at' => now()->subDays(30)]);
    Transfer::factory()->create(['expires_at' => now()->subDays(29)]);
    Transfer::factory()->create(['expires_at' => now()->subDays(90), 'purged_at' => now()]);
    $this->artisan('filemax:cleanup')->assertSuccessful();
    Queue::assertPushed(PurgeTransfer::class, 2);
});

it('does not mark a partially failed purge complete and can retry it safely', function (): void {
    $transfer = Transfer::factory()->create(['revoked_at' => now()]);
    $files = TransferFile::factory()->for($transfer)->count(2)->create();
    $disk = Storage::disk('local');
    foreach ($files as $file) {
        $disk->put($file->path, 'test');
    }

    $failing = Mockery::mock(FilesystemAdapter::class);
    $failing->shouldReceive('deleteDirectory')->andReturnTrue();
    $failing->shouldReceive('delete')->with($files[0]->path)->once()->andReturnUsing(fn (): bool => $disk->delete($files[0]->path));
    $failing->shouldReceive('delete')->with($files[1]->path)->once()->andReturnFalse();
    Storage::set('local', $failing);
    $job = new PurgeTransfer($transfer->id);

    expect(fn () => $job->handle(resolve(TransferStorage::class)))->toThrow(RuntimeException::class);
    expect($transfer->refresh()->purged_at)->toBeNull();

    $disk->assertMissing($files[0]->path);
    $disk->assertExists($files[1]->path);
    Storage::set('local', $disk);
    $job->handle(resolve(TransferStorage::class));
    $disk->assertMissing($files[1]->path);
    expect($transfer->refresh()->purged_at)->not->toBeNull();
});

it('recovers stalled archives and removes disposable old temporary files', function (): void {
    $transfer = Transfer::factory()->create(['archive_status' => 'processing', 'archive_requested_at' => now()->subHours(3)]);
    $directory = storage_path('app/archive-tmp/test-stale-'.$transfer->id);
    File::ensureDirectoryExists($directory);
    File::put($directory.'/partial.zip', 'incomplete');
    touch($directory, now()->subHours(3)->timestamp);
    $this->artisan('filemax:cleanup')->assertSuccessful();
    expect($transfer->refresh()->archive_status)->toBe('failed')->and(File::exists($directory))->toBeFalse();
});
