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
    public function show(Request $request, string $section = 'profile'): Response
    {
        $user = $request->user() ?? abort(403);

        if ($section === 'security') {
            return Inertia::render('account/settings', [
                'section' => $section,
                'passkeys' => $user->passkeys()->latest()->get()
                    ->map(fn (Passkey $passkey): array => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'created_at' => $passkey->created_at?->toIso8601String(),
                        'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                    ]),
            ]);
        }

        return Inertia::render('account/settings', [
            'section' => $section,
            'settings' => $user->settings->only(['show_name_on_transfers', 'notify_transfer_expiring', 'notify_transfer_downloaded']),
        ]);
    }

    public function update(UpdateAccountSettingsRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $user->setRelation('settings', $user->settings()->updateOrCreate([], $request->validated()));

        return back();
    }
}
