<?php

declare(strict_types=1);

use App\Http\Controllers\TransferController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::get('/', [TransferController::class, 'create'])
    ->middleware(['auth', EnsureEligibleEmail::class, 'verified'])->name('home');

require __DIR__.'/auth.php';
require __DIR__.'/teams.php';

require __DIR__.'/transfers.php';

require __DIR__.'/shared.php';
