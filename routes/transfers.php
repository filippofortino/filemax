<?php

declare(strict_types=1);

use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferUploadController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureEligibleEmail::class, 'verified'])->prefix('transfers')->name('transfers.')->group(function (): void {
    Route::get('/', [TransferController::class, 'index'])->name('index');
    Route::get('/create', [TransferController::class, 'create'])->name('create');
    Route::post('/', [TransferController::class, 'store'])->name('store');
    Route::get('/{transfer}', [TransferController::class, 'show'])->name('show');
    Route::patch('/{transfer}', [TransferController::class, 'update'])->name('update');
    Route::delete('/{transfer}', [TransferController::class, 'destroy'])->name('destroy');
    Route::post('/{transfer}/finalize', [TransferUploadController::class, 'finalize'])->name('uploads.finalize');
    Route::scopeBindings()->group(function (): void {
        Route::post('/{transfer}/files/{file}/parts/{part}/sign', [TransferUploadController::class, 'sign'])->whereNumber('part')->name('uploads.sign');
        Route::put('/{transfer}/files/{file}/parts/{part}', [TransferUploadController::class, 'upload'])->whereNumber('part')->name('uploads.upload');
        Route::post('/{transfer}/files/{file}/complete', [TransferUploadController::class, 'complete'])->name('uploads.complete');
        Route::delete('/{transfer}/files/{file}', [TransferUploadController::class, 'remove'])->name('uploads.remove');
    });
});
