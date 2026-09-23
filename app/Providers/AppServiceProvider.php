<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $disk = 'filesystems.disks.'.Config::string('filemax.disk', 'local');

        if (Config::get($disk.'.driver') === 's3') {
            // The SDK's automatic checksum pass rewinds nonseekable HTTP streams.
            Config::set($disk.'.response_checksum_validation', 'when_required');
        }
    }
}
