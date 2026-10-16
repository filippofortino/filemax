<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OpenSecretRequest;
use App\Models\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class OpenSecretController
{
    private const string TOO_MANY_TRIES = 'Too many tries. Wait a minute, then try again.';

    public function __invoke(OpenSecretRequest $request, Secret $secret): JsonResponse
    {
        $throttle = 'secret:'.$secret->id.'|'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($throttle, 5), 429, self::TOO_MANY_TRIES);
        abort_unless($secret->isAvailable(), 410);

        /** @var string $verifier */
        $verifier = $request->validated('verifier');

        if (! hash_equals($secret->verifier_hash, hash('sha256', $verifier))) {
            $secret->increment('wrong_key_count', 1, ['last_wrong_key_at' => now()]);
            RateLimiter::hit($throttle, 60);
            abort_if(RateLimiter::tooManyAttempts($throttle, 5), 429, self::TOO_MANY_TRIES);

            throw ValidationException::withMessages(['key' => 'That key doesn’t open this secret. Check it and try again.']);
        }

        $payload = $secret->payload;
        $now = now();
        // One conditional increment spends the view atomically on every driver, so only one request can take the last view.
        $spent = Secret::query()->whereKey($secret->id)
            ->whereNull('revoked_at')
            ->whereColumn('view_count', '<', 'max_views')
            ->where('expires_at', '>', $now)
            ->increment('view_count', 1, ['last_opened_at' => $now]);
        abort_if($spent === 0 || $payload === null, 410);
        $secret->refresh();

        if ($secret->view_count >= $secret->max_views) {
            $secret->update(['payload' => null]);
        }

        return response()->json([
            'payload' => $payload,
            'views_left' => max(0, $secret->max_views - $secret->view_count),
            'opened_at' => $now->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
