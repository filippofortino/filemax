<?php

declare(strict_types=1);

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\SwitchAccountController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::post('switch-account', SwitchAccountController::class)->middleware('auth')->name('account.switch');
Route::middleware(['auth', EnsureEligibleEmail::class, 'verified'])->group(function (): void {
    Route::get('account/settings', [AccountSettingsController::class, 'profile'])->name('account.settings');
    Route::get('account/settings/notifications', [AccountSettingsController::class, 'notifications'])->name('account.settings.notifications');
    Route::get('account/settings/security', [AccountSettingsController::class, 'security'])
        ->middleware('password.confirm')->name('account.settings.security');
    Route::put('account/settings', [AccountSettingsController::class, 'update'])->name('account.settings.update');
});
