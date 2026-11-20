<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationDomainRequest;
use App\Models\OrganizationDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class OrganizationDomainController
{
    public function store(StoreOrganizationDomainRequest $request): RedirectResponse
    {
        $organization = ($request->user() ?? abort(403))->currentOrganization();
        $domain = $organization->domains()->create($request->validated());

        Inertia::flash('toast', ['title' => 'Domain added', 'description' => "People who verify an @{$domain->domain} email now join {$organization->name}."]);

        return back();
    }

    public function destroy(Request $request, OrganizationDomain $domain): RedirectResponse
    {
        $organization = ($request->user() ?? abort(403))->currentOrganization();
        Gate::authorize('update', $organization);
        abort_unless($domain->organization_id === $organization->id, 404);
        $domain->delete();

        Inertia::flash('toast', ['title' => 'Domain removed', 'description' => 'Members who already joined with it stay.']);

        return back();
    }
}
