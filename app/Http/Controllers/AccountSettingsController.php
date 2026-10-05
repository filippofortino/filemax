<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

final class AccountSettingsController
{
    public function index(Request $request): Response
    {
        $user = $request->user() ?? abort(403);

        return Inertia::render('account/settings', [
            'passkeys' => $user->passkeys()->latest()->get()
                ->map(fn (Passkey $passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ]),
            'settings' => $user->settings->only(['show_name_on_transfers']),
        ]);
    }

    public function update(UpdateAccountSettingsRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $user->setRelation('settings', $user->settings()->updateOrCreate([], $request->validated()));

        return back();
    }
}
