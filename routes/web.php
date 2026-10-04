<?php

declare(strict_types=1);

use App\Http\Controllers\TransferController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', [TransferController::class, 'create'])
    ->middleware(['auth', EnsureEligibleEmail::class, 'verified'])->name('home');
Route::get('welcome', fn (): Response => Inertia::render('welcome'))
    ->middleware(['auth', EnsureEligibleEmail::class, 'verified'])->name('welcome');

require __DIR__.'/auth.php';
require __DIR__.'/teams.php';

require __DIR__.'/transfers.php';

require __DIR__.'/shared.php';
