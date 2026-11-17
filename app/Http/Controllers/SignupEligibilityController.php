<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\OrganizationDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** While sign-up is closed, tells someone whether their work email may register before showing the form. */
final class SignupEligibilityController
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var array{email: string} $data */
        $data = $request->validate(['email' => ['required', 'string', 'email:filter', 'max:255']]);

        if (! CreateNewUser::allows($data['email'])) {
            throw ValidationException::withMessages(['email' => 'No organization on Filemax uses '.OrganizationDomain::of($data['email']).'. Ask an admin of yours to invite you.']);
        }

        return back();
    }
}
