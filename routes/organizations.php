<?php

declare(strict_types=1);

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\OrganizationBrandingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationDomainController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\SignupEligibilityController;
use App\Http\Controllers\SwitchOrganizationController;
use App\Http\Middleware\EnsureCurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('organizations/{organization}/switch', SwitchOrganizationController::class)->name('organizations.switch');

    // Every route below acts on the organization chosen in the header, and only for its admins.
    Route::prefix('organization')->name('organization.')->group(function (): void {
        Route::get('/', [OrganizationController::class, 'show'])->name('show');
        Route::get('/members', [OrganizationController::class, 'show'])->defaults('section', 'members')->name('members');
        Route::get('/domains', [OrganizationController::class, 'show'])->defaults('section', 'domains')->name('domains');
        Route::get('/branding', [OrganizationController::class, 'show'])->defaults('section', 'branding')->name('branding');
        Route::post('/', [OrganizationController::class, 'update'])->middleware(EnsureCurrentOrganization::class)->name('update');
        Route::post('/branding', [OrganizationBrandingController::class, 'update'])->middleware(EnsureCurrentOrganization::class)->name('branding.update');
        Route::patch('/members/{user}', [OrganizationMemberController::class, 'update'])->middleware(EnsureCurrentOrganization::class)->name('members.update');
        Route::delete('/members/{user}', [OrganizationMemberController::class, 'destroy'])->middleware(EnsureCurrentOrganization::class)->name('members.destroy');
        Route::post('/invitations', [OrganizationInvitationController::class, 'store'])->middleware(['throttle:20,1', EnsureCurrentOrganization::class])->name('invitations.store');
        Route::post('/invitations/{invitation}/resend', [OrganizationInvitationController::class, 'update'])->middleware('throttle:20,1')->name('invitations.resend');
        Route::delete('/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])->name('invitations.destroy');
        Route::post('/domains', [OrganizationDomainController::class, 'store'])->middleware(EnsureCurrentOrganization::class)->name('domains.store');
        Route::delete('/domains/{domain}', [OrganizationDomainController::class, 'destroy'])->name('domains.destroy');
    });
});

Route::prefix('invites/{invitation}')->name('invitations.')->middleware('signed:relative')->whereUuid('invitation')->group(function (): void {
    Route::get('/', [AcceptInvitationController::class, 'show'])->name('show')
        ->missing(fn (Request $request) => Inertia::render('invitations/show', ['invitation' => null])->toResponse($request)->setStatusCode(404));
    Route::post('/accept', [AcceptInvitationController::class, 'update'])->middleware('auth')->name('accept');
    Route::post('/register', [AcceptInvitationController::class, 'store'])->middleware(['guest', 'throttle:5,1'])->name('register');
});

Route::post('register/check', SignupEligibilityController::class)->middleware(['guest', 'throttle:10,1'])->name('register.check');
