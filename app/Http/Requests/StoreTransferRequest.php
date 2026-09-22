<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
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
            'visibility' => ['required', Rule::in(['public', 'teams'])],
            'expires_in_days' => ['required', 'integer', Rule::in([1, 7, 14, 30])],
            'team_ids' => ['array', 'list', Rule::requiredIf($this->input('visibility') === 'teams'), $this->input('visibility') === 'teams' ? 'min:1' : 'max:0'],
            'team_ids.*' => ['required', 'uuid', 'distinct', Rule::in($this->user()?->teams()->pluck('teams.id')->all() ?? [])],
            'files' => ['required', 'array', 'list', 'min:1'],
            'files.*' => ['required', 'array:name,size,type'],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.size' => ['required', 'integer', 'min:0'],
            'files.*.type' => ['nullable', 'string', 'max:255'],
        ];
    }
}
