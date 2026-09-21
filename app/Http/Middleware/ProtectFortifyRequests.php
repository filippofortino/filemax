<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ProtectFortifyRequests
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login.store', 'register.store', 'password.email', 'password.update')) {
            if (is_string($request->input('email'))) {
                $request->merge(['email' => Str::lower(mb_trim($request->input('email')))]);
            }

            $rules = [
                'email' => ['required', 'string', 'email:filter', 'max:255', 'ends_with:@mediamaxcommunication.it'],
            ];
            if ($request->routeIs('login.store')) {
                $rules['remember'] = ['sometimes', 'boolean'];
            }

            if ($request->routeIs('password.update')) {
                $rules['token'] = ['required', 'string'];
            }

            Validator::make($request->only(array_keys($rules)), $rules)->validate();
        }

        if ($request->user() && $request->routeIs('verification.*')) {
            abort_unless($request->user()->isEligible(), 403);

        }

        return $next($request);
    }
}
