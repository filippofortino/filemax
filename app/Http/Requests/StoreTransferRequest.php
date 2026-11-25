<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use App\Models\TransferFile;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Transfer::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            // Personal has nobody to restrict a link to.
            'visibility' => ['required', Rule::in($this->user()?->currentOrganization()->personal === false ? ['public', 'members'] : ['public'])],
            'password_protected' => ['sometimes', 'boolean', Rule::prohibitedIf($this->input('visibility') !== 'public' && $this->boolean('password_protected'))],
            'password' => [
                'bail',
                Rule::requiredIf($this->boolean('password_protected')),
                Rule::prohibitedIf(! $this->boolean('password_protected') || $this->input('visibility') !== 'public'),
                'nullable',
                'string',
                'min:8',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (mb_strlen($value, '8bit') > 72 || str_contains($value, "\0"))) {
                        $fail('The password must not exceed 72 bytes or contain null characters.');
                    }
                },
            ],
            'password_hash' => ['missing'],
            'expires_in_days' => ['required', 'integer', Rule::in([1, 7, 14, 30])],
            'files' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'files.*' => ['required', 'array:name,size,type,folder'],
            'files.*.folder' => ['nullable', 'string', 'max:1024', 'not_regex:'.TransferFile::UNSAFE_FOLDER],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.size' => ['required', 'integer', 'min:0'],
            'files.*.type' => ['nullable', 'string', 'max:255'],
        ];
    }
}
