<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTransferSharingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('transfer');

        return $transfer instanceof Transfer && ($this->user()?->can('update', $transfer) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'team_ids' => ['required', 'array', 'list', 'min:1'],
            'team_ids.*' => ['required', 'uuid', 'distinct', Rule::in($this->user()?->teams()->pluck('teams.id')->all() ?? [])],
        ];
    }
}
