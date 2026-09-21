<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class TeamMemberController
{
    public function store(TeamMemberRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);
        $team->users()->syncWithoutDetaching([$request->string('user_id')->toString()]);

        return to_route('teams.index')->with('status', 'Member added.');
    }

    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('update', $team);
        $team->users()->detach($user);

        return to_route('teams.index')->with('status', 'Member removed.');
    }
}
