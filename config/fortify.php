<?php

declare(strict_types=1);

use App\Http\Middleware\ProtectFortifyRequests;
use Laravel\Fortify\Features;

$appUrl = env('APP_URL', 'http://localhost');
$appUrl = is_string($appUrl) ? $appUrl : 'http://localhost';

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/',
    'middleware' => ['web', 'throttle:filemax-auth', ProtectFortifyRequests::class],
    'views' => true,
    'limiters' => ['login' => 'login', 'passkeys' => 'passkeys'],
    'redirects' => ['logout' => '/login', 'password-reset' => '/login', 'password-confirmation' => '/account/settings'],
    'passkeys' => [
        'relying_party_id' => parse_url($appUrl, PHP_URL_HOST),
        'allowed_origins' => [$appUrl],
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', env('APP_KEY')),
        'timeout' => 60000,
    ],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::updateProfileInformation(),
        Features::emailVerification(),
        Features::passkeys(['confirmPassword' => true]),
    ],
];
