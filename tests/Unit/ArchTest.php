<?php

declare(strict_types=1);

use App\Http\Controllers\TransferUploadController;

arch()->preset()->php();
arch()->preset()->strict();
arch()->preset()->laravel()->ignoring([
    TransferUploadController::class,
]);
arch()->preset()->security()->ignoring([
    'assert',
]);

arch('controllers')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed();
