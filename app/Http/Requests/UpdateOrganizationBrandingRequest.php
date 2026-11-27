<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOrganizationBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()->currentOrganization()) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'brand_color' => [
                'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 && Organization::contrastWithWhite($value) < 4.5) {
                        $fail('This color is too light to read on white. Pick a darker shade.');
                    }
                },
            ],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'footer_text' => ['nullable', 'string', 'max:120'],
            'footer_url' => ['nullable', 'string', 'url:https', 'max:255'],
            'wordmark' => [
                'nullable', 'prohibited_if_accepted:remove_wordmark', 'image', 'mimes:jpg,jpeg,png', 'max:5120',
                Rule::dimensions()->minWidth(600)->maxWidth(4096)->maxHeight(4096),
            ],
            'remove_wordmark' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'brand_color.regex' => 'Enter six hex digits after the #, like #2140E0.',
            'footer_url.url' => 'Use a full address that starts with https://.',
            'wordmark.mimes' => 'The full logo must be a JPG or PNG.',
            'wordmark.dimensions' => 'The full logo must be at least 600 px wide.',
        ];
    }
}
