<?php

declare(strict_types=1);

use App\Http\Middleware\ProtectFortifyRequests;
use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/',
    'middleware' => ['web', 'throttle:filemax-auth', ProtectFortifyRequests::class],
    'views' => true,
    'limiters' => ['login' => 'login'],
    'redirects' => ['logout' => '/login', 'password-reset' => '/login'],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
