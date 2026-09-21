<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SwitchAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class SwitchAccountController
{
    public function __invoke(SwitchAccountRequest $request): RedirectResponse
    {
        $returnTo = $request->string('return_to')->toString();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', $returnTo);

        return to_route('login');
    }
}
