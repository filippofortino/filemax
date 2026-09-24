<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

final class AccountSettingsController
{
    public function index(Request $request): Response
    {
        return Inertia::render('account/settings', [
            'passkeys' => ($request->user() ?? abort(403))->passkeys()->latest()->get()
                ->map(fn (Passkey $passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ]),
        ]);
    }
}
