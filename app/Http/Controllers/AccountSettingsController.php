<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

final class AccountSettingsController
{
    public function profile(Request $request): Response
    {
        return Inertia::render('account/settings', [
            'section' => 'profile',
            'settings' => $this->settings($request->user() ?? abort(403)),
        ]);
    }

    public function notifications(Request $request): Response
    {
        return Inertia::render('account/settings', [
            'section' => 'notifications',
            'settings' => $this->settings($request->user() ?? abort(403)),
        ]);
    }

    public function security(Request $request): Response
    {
        $user = $request->user() ?? abort(403);

        return Inertia::render('account/settings', [
            'section' => 'security',
            'passkeys' => $user->passkeys()->latest()->get()
                ->map(fn (Passkey $passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ]),
        ]);
    }

    public function update(UpdateAccountSettingsRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $user->setRelation('settings', $user->settings()->updateOrCreate([], $request->validated()));

        return back();
    }

    /** @return array<string, mixed> */
    private function settings(User $user): array
    {
        return $user->settings->only(['show_name_on_transfers', 'notify_transfer_expiring', 'notify_transfer_downloaded']);
    }
}
