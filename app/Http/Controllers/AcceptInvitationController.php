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
use Illuminate\Support\Facades\URL;
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
                'return_path' => $request->getRequestUri(),
                'accept_url' => URL::signedRoute('invitations.accept', $invitation, absolute: false),
                'register_url' => URL::signedRoute('invitations.register', $invitation, absolute: false),
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
        DB::transaction(function () use ($user, $invitation): void {
            $invite = $this->activeInvitation($invitation);
            $member = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($member->email === $invite->email, 403);
            $this->join($member, $invite);
        });

        return to_route('home');
    }

    /** Creates the account for the invited email and joins. */
    public function store(RegisterWithInvitationRequest $request, OrganizationInvitation $invitation): RedirectResponse
    {
        /** @var array{name: string, password: string} $data */
        $data = $request->validated();
        $user = DB::transaction(function () use ($invitation, $data): User {
            $invite = $this->activeInvitation($invitation);
            $user = User::query()->create([...$data, 'email' => $invite->email]);
            $this->join($user, $invite);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('home');
    }

    /** Recheck the invitation inside the transaction that consumes it. */
    private function activeInvitation(OrganizationInvitation $invitation): OrganizationInvitation
    {
        $invite = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
        abort_if($invite->expires_at->isPast(), 410);

        return $invite;
    }

    private function join(User $user, OrganizationInvitation $invitation): void
    {
        $organization = $invitation->organization;
        $invitation->accept($user);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        Inertia::flash('toast', ['title' => "You joined {$organization->name}", 'description' => 'Switch between it and Personal in the header.']);
    }
}
