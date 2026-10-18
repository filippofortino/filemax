<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Drop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexDropsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Drop::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'nullable', 'string', Rule::in(['all', 'open', 'closed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
