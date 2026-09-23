<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    /** @param array<string, mixed> $input */
    public function update(User $user, array $input): void
    {
        /** @var array{current_password: string, password: string} $validated */
        $validated = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $validated['password']])->save();
    }
}
