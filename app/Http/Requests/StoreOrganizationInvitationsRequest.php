<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreOrganizationInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()->currentOrganization()) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'emails.*' => ['required', 'string', 'email:filter', 'max:255'],
            'role' => ['required', Rule::in(Organization::ROLES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'emails.required' => 'Enter at least one email address.',
            'emails.max' => 'Invite up to 50 people at a time.',
            'emails.*.email' => '“:input” isn’t a valid email address.',
        ];
    }

    public function prepareForValidation(): void
    {
        $emails = $this->input('emails');

        if (is_array($emails)) {
            $this->merge(['emails' => array_values(array_unique(array_map(
                fn (mixed $email): mixed => is_string($email) ? Str::lower(mb_trim($email)) : $email,
                $emails,
            ), SORT_REGULAR))]);
        }
    }
}
