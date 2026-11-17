<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\OrganizationDomain;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    /** While sign-up is closed, an email may register only if some organization lists its domain. Invites have their own route. */
    public static function allows(string $email): bool
    {
        return config()->boolean('filemax.signup_open')
            || OrganizationDomain::query()->where('domain', OrganizationDomain::of($email))->exists();
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:255', Rule::unique(User::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! self::allows($value)) {
                        $fail('Sign-up is by invitation for now. Ask an admin of your organization to invite you.');
                    }
                },
            ],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ])->validate();

        return User::query()->create($validated);
    }
}
