<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexTransfersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Transfer::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'filter' => ['bail', 'nullable', 'string', Rule::anyOf([[Rule::in(['all', 'public'])], ['uuid']])],
            'status' => ['bail', 'nullable', 'string', Rule::in(['all', 'active', 'soon', 'expired'])],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => mb_trim($this->input('search'))]);
        }
    }
}
