<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationMemberRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class OrganizationMemberController
{
    public function update(UpdateOrganizationMemberRequest $request, User $user): RedirectResponse
    {
        $organization = $this->organization($request, $user);
        $organization->users()->updateExistingPivot($user->id, ['role' => $request->string('role')->toString()]);

        Inertia::flash('toast', ['title' => 'Role updated']);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $organization = $this->organization($request, $user);
        Gate::authorize('update', $organization);
        $organization->users()->detach($user->id);
        User::query()->whereKey($user->id)->where('current_organization_id', $organization->id)->update(['current_organization_id' => null]);

        Inertia::flash('toast', ['title' => 'Member removed', 'description' => "{$user->name} can no longer send as {$organization->name} or open its members-only links."]);

        return back();
    }

    /** Admins manage everyone but themselves, so an organization always keeps at least one admin. */
    private function organization(Request $request, User $member): Organization
    {
        $admin = $request->user() ?? abort(403);
        $organization = $admin->currentOrganization();
        abort_if($member->is($admin), 403, 'You can’t change your own membership.');
        abort_unless($organization->hasMember($member), 404);

        return $organization;
    }
}
