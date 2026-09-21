<?php

declare(strict_types=1);

use App\Services\TransferStorage;
use Illuminate\Foundation\CloudBootstrapper;
use Illuminate\Support\Facades\Storage;

it('uses the private Cloud transfer bucket independently of the default assets bucket', function (): void {
    $originalServer = $_SERVER;
    $connection = [
        'access_key_id' => 'test',
        'access_key_secret' => 'test',
        'url' => null,
        'endpoint' => 'https://r2.example.com',
    ];

    try {
        $_SERVER['FILESYSTEM_DISK'] = 'assets';
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([
            ['disk' => 'transfers', 'bucket' => 'private-transfers', 'is_default' => false] + $connection,
            ['disk' => 'assets', 'bucket' => 'public-assets', 'is_default' => true] + $connection,
        ], JSON_THROW_ON_ERROR);
        config(['filemax.disk' => 'transfers']);

        CloudBootstrapper::configureDisks($this->app);
        $storage = resolve(TransferStorage::class);

        expect($storage->isRemote())->toBeTrue()
            ->and($storage->disk()->getConfig()['bucket'])->toBe('private-transfers')
            ->and(Storage::disk()->getConfig()['bucket'])->toBe('public-assets');
    } finally {
        $_SERVER = $originalServer;
    }
});
