<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegisterWithInvitationRequest;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** The invited person's side of an invite. The link itself proves they own the email it was sent to. */
final class AcceptInvitationController
{
    public function show(Request $request, OrganizationInvitation $invitation): Response
    {
        if ($invitation->expires_at->isPast()) {
            return Inertia::render('invitations/show', ['invitation' => null])->toResponse($request)->setStatusCode(410);
        }

        $user = $request->user();
        $registered = User::query()->where('email', $invitation->email)->exists();

        if (! $user && $registered) {
            return redirect()->guest(route('login'));
        }

        return Inertia::render('invitations/show', [
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'organization' => [...$invitation->organization->only(['name']), 'logo_url' => $invitation->organization->logoUrl()],
                'inviter' => $invitation->inviter?->name,
                'state' => match (true) {
                    ! $user => 'register',
                    $user->email === $invitation->email => 'join',
                    default => 'wrong_account',
                },
            ],
        ])->toResponse($request);
    }

    /** Joins as the signed-in user. */
    public function update(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        abort_if($invitation->expires_at->isPast(), 410);
        abort_unless($user->email === $invitation->email, 403);

        return $this->join($user, $invitation);
    }

    /** Creates the account for the invited email and joins. */
    public function store(RegisterWithInvitationRequest $request, OrganizationInvitation $invitation): RedirectResponse
    {
        /** @var array{name: string, password: string} $data */
        $data = $request->validated();
        $user = User::query()->create([...$data, 'email' => $invitation->email]);
        Auth::login($user);
        $request->session()->regenerate();

        return $this->join($user, $invitation);
    }

    private function join(User $user, OrganizationInvitation $invitation): RedirectResponse
    {
        $organization = $invitation->organization;
        DB::transaction(fn () => $invitation->accept($user));

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        Inertia::flash('toast', ['title' => "You joined {$organization->name}", 'description' => 'Switch between it and Personal in the header.']);

        return to_route('home');
    }
}
