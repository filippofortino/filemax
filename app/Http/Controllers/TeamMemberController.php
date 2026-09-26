<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class TeamMemberController
{
    public function store(TeamMemberRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);
        $team->users()->syncWithoutDetaching([$request->string('user_id')->toString()]);

        Inertia::flash('toast', ['title' => 'Member added']);

        return to_route('teams.index');
    }

    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('update', $team);
        $team->users()->detach($user);

        Inertia::flash('toast', ['title' => 'Member removed']);

        return to_route('teams.index');
    }
}
