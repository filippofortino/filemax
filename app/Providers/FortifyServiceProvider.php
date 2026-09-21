<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Http\Responses\RegisterResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn (): Response => Inertia::render('auth/login'));
        Fortify::registerView(fn (): Response => Inertia::render('auth/register'));
        Fortify::requestPasswordResetLinkView(fn (): Response => Inertia::render('auth/forgot-password'));
        Fortify::resetPasswordView(fn (Request $request): Response => Inertia::render('auth/reset-password', [
            'token' => $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]));
        Fortify::verifyEmailView(fn (): Response => Inertia::render('auth/verify-email'));

        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(5)->by((is_string($email) ? Str::lower(mb_trim($email)) : '').'|'.$request->ip());
        });
        RateLimiter::for('filemax-auth', fn (Request $request): Limit => $request->routeIs('register.store', 'password.email', 'password.update')
            ? Limit::perMinute(5)->by($request->route()?->getName().'|'.$request->ip())
            : Limit::none());
    }
}
