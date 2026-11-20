<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()->currentOrganization()) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'logo' => [
                'nullable', 'prohibited_if_accepted:remove_logo', 'image', 'mimes:jpg,jpeg,png', 'max:5120',
                Rule::dimensions()->minWidth(200)->minHeight(200)->maxWidth(4096)->maxHeight(4096),
            ],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }
}
