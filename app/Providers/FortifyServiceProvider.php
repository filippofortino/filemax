<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Http\Responses\RegisterResponse;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;

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
        Event::listen(Login::class, function (Login $event): void {
            $guard = Auth::guard($event->guard);

            if ($guard instanceof SessionGuard) {
                session()->put('password_hash_'.$event->guard, $guard->hashPasswordForCookie($event->user->getAuthPassword()));
            }
        });

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::loginView(fn (): Response => Inertia::render('auth/login'));
        Fortify::registerView(fn (): Response => Inertia::render('auth/register'));
        Fortify::requestPasswordResetLinkView(fn (): Response => Inertia::render('auth/forgot-password'));
        Fortify::resetPasswordView(fn (Request $request): Response => Inertia::render('auth/reset-password', [
            'token' => $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]));
        Fortify::verifyEmailView(fn (): Response => Inertia::render('auth/verify-email'));
        Fortify::confirmPasswordView(fn (Request $request): Response => Inertia::render('auth/confirm-password', [
            'hasPasskeys' => $request->user()?->hasPasskeysEnabled() ?? false,
        ]));

        Passkeys::authorizeLoginUsing(fn (Request $request, PasskeyUser $user): bool => $user instanceof User && $user->isEligible());

        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute(5)->by((is_string($email) ? Str::lower(mb_trim($email)) : '').'|'.$request->ip());
        });
        RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(10)->by($request->session()->getId().'|'.$request->ip()));
        RateLimiter::for('filemax-auth', fn (Request $request): Limit => $request->routeIs('register.store', 'password.email', 'password.update', 'password.confirm.store', 'user-password.update', 'user-profile-information.update')
            ? Limit::perMinute(5)->by($request->route()?->getName().'|'.$request->ip())
            : Limit::none());
    }
}
