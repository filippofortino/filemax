<?php

declare(strict_types=1);

use App\Http\Controllers\TransferDownloadController;
use App\Http\Controllers\TransferUploadController;

arch()->preset()->php();
arch()->preset()->strict();
// Workflow actions stay together instead of requiring a controller for every endpoint.
arch()->preset()->laravel()->ignoring([
    TransferDownloadController::class,
    TransferUploadController::class,
]);
arch()->preset()->security()->ignoring([
    'assert',
]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed();
