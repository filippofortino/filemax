<?php

declare(strict_types=1);

use App\Http\Controllers\SharedTransferController;
use App\Http\Controllers\TransferArchiveController;
use App\Http\Controllers\TransferArchiveDownloadController;
use App\Http\Controllers\TransferFileDownloadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/t/{transfer:token}', [SharedTransferController::class, 'show'])->name('shared.show')
    ->missing(fn (Request $request) => Inertia::render('shared/unavailable', ['reason' => 'unavailable'])->toResponse($request)->setStatusCode(404));

Route::scopeBindings()->prefix('t/{transfer:token}')->name('shared.')->group(function (): void {
    Route::post('/files/{file}/download', [TransferFileDownloadController::class, 'store'])->name('files.download');
    Route::get('/files/{file}/content', [TransferFileDownloadController::class, 'show'])->middleware('signed')->name('files.content');
    Route::post('/archive', [TransferArchiveController::class, 'store'])->name('download');
    Route::get('/archive', [TransferArchiveController::class, 'show'])->name('archive');
    Route::get('/archive/download', [TransferArchiveDownloadController::class, 'show'])->name('archive.download');
});
