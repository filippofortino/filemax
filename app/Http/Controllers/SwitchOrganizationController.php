<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SwitchOrganizationController
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        abort_unless($organization->hasMember($user), 403);
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        // Organization settings belong to the organization just left.
        return str_starts_with((string) parse_url(url()->previous(), PHP_URL_PATH), '/organization') ? to_route('home') : back();
    }
}
