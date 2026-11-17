<?php

declare(strict_types=1);

use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', [TransferController::class, 'create'])
    ->middleware(['auth', 'verified'])->name('home');
Route::get('welcome', fn (): Response => Inertia::render('welcome'))
    ->middleware(['auth', 'verified'])->name('welcome');

require __DIR__.'/auth.php';
require __DIR__.'/organizations.php';

require __DIR__.'/transfers.php';
require __DIR__.'/secrets.php';
require __DIR__.'/drops.php';

require __DIR__.'/shared.php';
