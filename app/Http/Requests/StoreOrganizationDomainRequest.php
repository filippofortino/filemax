<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OrganizationDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreOrganizationDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()->currentOrganization()) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                Rule::unique(OrganizationDomain::class)->where('organization_id', $this->user()?->currentOrganization()->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a domain like example.com, without @ or https://.',
            'domain.unique' => 'This domain is already on the list.',
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => Str::lower(mb_ltrim(mb_trim($this->input('domain')), '@'))]);
        }
    }
}
