<?php

declare(strict_types=1);

use App\Http\Controllers\CloseDropController;
use App\Http\Controllers\DropController;
use App\Http\Controllers\ReceivedController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureEligibleEmail::class, 'verified'])->group(function (): void {
    Route::prefix('drops')->name('drops.')->group(function (): void {
        Route::get('/', [DropController::class, 'index'])->name('index');
        Route::get('/create', [DropController::class, 'create'])->name('create');
        Route::post('/', [DropController::class, 'store'])->name('store');
        Route::post('/{drop}/close', CloseDropController::class)->name('close');
    });

    // A tab of My transfers; {transfer} on the transfer routes only matches UUIDs, so "received" never reaches them.
    Route::prefix('transfers/received')->name('received.')->group(function (): void {
        Route::get('/', [ReceivedController::class, 'index'])->name('index');
        Route::get('/{transfer}', [ReceivedController::class, 'show'])->name('show');
        Route::delete('/{transfer}', [ReceivedController::class, 'destroy'])->name('destroy');
    });
});
