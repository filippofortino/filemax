<?php

declare(strict_types=1);

use App\Http\Controllers\SecretController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureEligibleEmail::class, 'verified'])->prefix('secrets')->name('secrets.')->group(function (): void {
    Route::get('/create', [SecretController::class, 'create'])->name('create');
    Route::post('/', [SecretController::class, 'store'])->name('store');
    Route::get('/{secret}', [SecretController::class, 'show'])->name('show');
    Route::delete('/{secret}', [SecretController::class, 'destroy'])->name('destroy');
});
