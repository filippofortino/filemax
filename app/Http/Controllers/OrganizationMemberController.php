<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationMemberRequest;
use App\Models\Organization;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        DB::transaction(function () use ($organization, $user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $organization->users()->detach($user->id);
            if ($user->current_organization_id === $organization->id) {
                $user->forceFill(['current_organization_id' => null])->save();
            }

            $user->drops()->whereBelongsTo($organization)->whereNull('closed_at')->where('closes_at', '>', now())->update(['closed_at' => now()]);
            Transfer::query()->whereBelongsTo($user)->whereBelongsTo($organization)->whereNotNull('drop_id')
                ->where('status', 'uploading')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

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
