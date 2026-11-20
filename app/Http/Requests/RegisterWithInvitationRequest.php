<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterWithInvitationRequest extends FormRequest
{
    /** A live invite whose email has no account yet is all it takes: sign-up being closed does not apply. */
    public function authorize(): bool
    {
        $invitation = $this->route('invitation');
        abort_unless($invitation instanceof OrganizationInvitation, 404);
        abort_if($invitation->expires_at->isPast(), 410);

        return ! User::query()->where('email', $invitation->email)->exists();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
