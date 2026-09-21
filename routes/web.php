<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureEligibleEmail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', fn (): Response => Inertia::render('index'))
    ->middleware(['auth', EnsureEligibleEmail::class, 'verified'])->name('home');

require __DIR__.'/auth.php';
require __DIR__.'/teams.php';
