<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TeamRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Team::class);

        return Inertia::render('teams/index', [
            'teams' => Team::query()->with('users:id,name,email')->withCount('users')->orderBy('name')->get(),
            'users' => User::query()->select(['id', 'name', 'email'])->orderBy('name')->get()->filter(fn (User $user): bool => $user->isEligible())->values(),
        ]);
    }

    public function store(TeamRequest $request): RedirectResponse
    {
        Team::query()->create($request->validated());

        return to_route('teams.index')->with('status', 'Team created.');
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);
        $team->update($request->validated());

        return to_route('teams.index')->with('status', 'Team renamed.');
    }
}
