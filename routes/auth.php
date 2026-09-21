<?php

declare(strict_types=1);

use App\Http\Controllers\SwitchAccountController;
use Illuminate\Support\Facades\Route;

Route::post('switch-account', SwitchAccountController::class)->middleware('auth')->name('account.switch');
