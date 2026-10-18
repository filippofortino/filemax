<?php

declare(strict_types=1);

use App\Http\Controllers\DropSubmissionController;
use App\Http\Controllers\OpenSecretController;
use App\Http\Controllers\SharedDropController;
use App\Http\Controllers\SharedSecretController;
use App\Http\Controllers\SharedTransferController;
use App\Http\Controllers\TransferArchiveController;
use App\Http\Controllers\TransferArchiveDownloadController;
use App\Http\Controllers\TransferFileDownloadController;
use App\Http\Controllers\TransferFileUploadController;
use App\Http\Controllers\TransferUploadPartController;
use App\Http\Controllers\UnlockTransferController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/t/{transfer:token}', [SharedTransferController::class, 'show'])->name('shared.show')
    ->missing(fn (Request $request) => Inertia::render('shared/unavailable', ['reason' => 'unavailable'])->toResponse($request)->setStatusCode(404));

Route::scopeBindings()->prefix('t/{transfer:token}')->name('shared.')->group(function (): void {
    Route::post('/unlock', UnlockTransferController::class)->name('unlock');
    Route::post('/files/{file}/download', [TransferFileDownloadController::class, 'store'])->name('files.download');
    Route::get('/files/{file}/content', [TransferFileDownloadController::class, 'show'])->middleware('signed')->name('files.content');
    Route::post('/archive', [TransferArchiveController::class, 'store'])->name('download');
    Route::get('/archive', [TransferArchiveController::class, 'show'])->name('archive');
    Route::get('/archive/download', [TransferArchiveDownloadController::class, 'show'])->name('archive.download');
});

Route::get('/s/{secret:token}', [SharedSecretController::class, 'show'])->name('shared.secrets.show')
    ->missing(fn (Request $request) => Inertia::render('shared/secret', ['secret' => null])->toResponse($request)->setStatusCode(404));
Route::post('/s/{secret:token}/open', OpenSecretController::class)->name('shared.secrets.open');

Route::get('/d/{drop:token}', [SharedDropController::class, 'show'])->name('shared.drops.show')
    ->missing(fn (Request $request) => Inertia::render('shared/drop', ['drop' => null])->toResponse($request)->setStatusCode(404));
Route::post('/d/{drop:token}/submissions', [DropSubmissionController::class, 'store'])->middleware('throttle:drop-submissions')->name('shared.drops.submissions.store');

// Drop uploads reuse the transfer upload controllers, so they carry the same {transfer}/{file}/{part} parameters.
Route::prefix('drop-uploads/{transfer}')->name('shared.drops.uploads.')->group(function (): void {
    Route::post('/', [DropSubmissionController::class, 'update'])->name('finalize');
    Route::delete('/', [DropSubmissionController::class, 'destroy'])->name('cancel');
    Route::scopeBindings()->group(function (): void {
        Route::post('/files/{file}/parts/{part}', [TransferUploadPartController::class, 'store'])->whereNumber('part')->name('sign');
        Route::put('/files/{file}/parts/{part}', [TransferUploadPartController::class, 'update'])->whereNumber('part')->name('upload');
        Route::post('/files/{file}/upload', [TransferFileUploadController::class, 'store'])->name('complete');
        Route::delete('/files/{file}/upload', [TransferFileUploadController::class, 'destroy'])->name('remove');
    });
});
