<?php

declare(strict_types=1);

use App\Http\Controllers\SharedTransferController;
use App\Http\Controllers\TransferDownloadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/t/{transfer:token}', [SharedTransferController::class, 'show'])->name('shared.show')
    ->missing(fn (Request $request) => Inertia::render('shared/unavailable', ['reason' => 'unavailable'])->toResponse($request)->setStatusCode(404));
Route::post('/t/{token}/files/{file}/download', [TransferDownloadController::class, 'file'])->name('shared.files.download');
Route::get('/t/{token}/files/{file}/content', [TransferDownloadController::class, 'content'])->middleware('signed')->name('shared.files.content');
Route::post('/t/{token}/download', [TransferDownloadController::class, 'all'])->name('shared.download');
Route::get('/t/{token}/archive', [TransferDownloadController::class, 'archive'])->name('shared.archive');
Route::get('/t/{token}/archive/download', [TransferDownloadController::class, 'archiveDownload'])->name('shared.archive.download');
