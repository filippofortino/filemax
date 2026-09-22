<?php

declare(strict_types=1);

use App\Http\Controllers\AccountPasskeyController;
use App\Http\Controllers\SwitchAccountController;
use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;

Route::post('switch-account', SwitchAccountController::class)->middleware('auth')->name('account.switch');
Route::get('account/passkeys', [AccountPasskeyController::class, 'index'])
    ->middleware(['auth', EnsureEligibleEmail::class, 'verified', 'password.confirm'])
    ->name('account.passkeys');
