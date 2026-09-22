<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, mixed> $input */
    public function reset(User $user, array $input): void
    {
        /** @var array{password: string} $validated */
        $validated = Validator::make($input, [
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ])->validate();

        $user->forceFill($validated)->save();
    }
}
