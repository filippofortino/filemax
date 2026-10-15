<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UnlockTransferRequest;
use App\Models\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class UnlockTransferController
{
    public function __invoke(UnlockTransferRequest $request, Transfer $transfer): RedirectResponse
    {
        $key = 'transfer-unlock:'.$transfer->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many tries. Wait a minute, then try again.'])
                ->redirectTo(route('shared.show', $transfer->token));
        }

        if (! Hash::check($request->string('password')->toString(), $transfer->password_hash ?? abort(403))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['password' => 'That password isn’t right. Check it and try again.'])
                ->redirectTo(route('shared.show', $transfer->token));
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('unlocked_transfers.'.$transfer->id, true);

        return to_route('shared.show', $transfer->token);
    }
}
