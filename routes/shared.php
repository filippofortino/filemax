<?php

declare(strict_types=1);

use App\Http\Controllers\SharedTransferController;
use App\Http\Controllers\TransferDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/t/{token}', [SharedTransferController::class, 'show'])->name('shared.show');
Route::post('/t/{token}/files/{file}/download', [TransferDownloadController::class, 'file'])->name('shared.files.download');
Route::get('/t/{token}/files/{file}/content', [TransferDownloadController::class, 'content'])->middleware('signed')->name('shared.files.content');
Route::post('/t/{token}/download', [TransferDownloadController::class, 'all'])->name('shared.download');
Route::get('/t/{token}/archive', [TransferDownloadController::class, 'archive'])->name('shared.archive');
Route::get('/t/{token}/archive/download', [TransferDownloadController::class, 'archiveDownload'])->name('shared.archive.download');
