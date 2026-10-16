<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Secret;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Secret::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            // Base64 of IV + AES-GCM ciphertext for up to 10,000 characters of UTF-8 text.
            'payload' => ['required', 'string', 'min:40', 'max:60000', 'regex:/^[A-Za-z0-9+\/]+={0,2}$/'],
            'verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'max_views' => ['required', 'integer', Rule::in([1, 5])],
            'expires_in_hours' => ['required', 'integer', Rule::in([1, 24, 168])],
        ];
    }
}
