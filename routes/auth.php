<?php

declare(strict_types=1);

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\SwitchAccountController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::post('switch-account', SwitchAccountController::class)->middleware('auth')->name('account.switch');
Route::get('account/settings', [AccountSettingsController::class, 'index'])
    ->middleware(['auth', EnsureEligibleEmail::class, 'verified'])
    ->name('account.settings');
