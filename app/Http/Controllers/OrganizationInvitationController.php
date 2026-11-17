<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationInvitationsRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Notifications\OrganizationInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

final class OrganizationInvitationController
{
    public function store(StoreOrganizationInvitationsRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $organization = $user->currentOrganization();
        /** @var array{emails: list<string>, role: string} $data */
        $data = $request->validated();
        /** @var list<string> $members */
        $members = $organization->users()->whereIn('email', $data['emails'])->pluck('email')->all();
        $emails = array_values(array_diff($data['emails'], $members));

        foreach ($emails as $email) {
            // Inviting someone again replaces their pending invite and restarts its 7 days.
            $invitation = $organization->invitations()->updateOrCreate(['email' => $email], [
                'role' => $data['role'],
                'invited_by' => $user->id,
                'expires_at' => now()->addDays(OrganizationInvitation::VALID_DAYS),
            ]);
            Notification::route('mail', $email)->notify(new OrganizationInvite($invitation));
        }

        $sent = count($emails);
        Inertia::flash('toast', $sent === 0
            ? ['type' => 'error', 'title' => 'No invites sent', 'description' => 'Everyone you entered is already a member.']
            : ['title' => $sent === 1 ? 'Invite sent' : "{$sent} invites sent", 'description' => $members === [] ? null : 'People who are already members were skipped.']);

        return back();
    }

    /** Resends the email and restarts the invite's 7 days. */
    public function update(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->organization($request, $invitation);
        $invitation->update(['expires_at' => now()->addDays(OrganizationInvitation::VALID_DAYS)]);
        Notification::route('mail', $invitation->email)->notify(new OrganizationInvite($invitation));

        Inertia::flash('toast', ['title' => 'Invite sent again', 'description' => "{$invitation->email} has 7 more days to join."]);

        return back();
    }

    public function destroy(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $this->organization($request, $invitation);
        $invitation->delete();

        Inertia::flash('toast', ['title' => 'Invite cancelled', 'description' => "The link sent to {$invitation->email} no longer works."]);

        return back();
    }

    private function organization(Request $request, OrganizationInvitation $invitation): Organization
    {
        $organization = ($request->user() ?? abort(403))->currentOrganization();
        Gate::authorize('update', $organization);
        abort_unless($invitation->organization_id === $organization->id, 404);

        return $organization;
    }
}
