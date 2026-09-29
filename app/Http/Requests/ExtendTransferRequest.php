<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;

final class ExtendTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('transfer');

        return $transfer instanceof Transfer && ($this->user()?->can('update', $transfer) ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'expected_expires_at' => ['required', 'string', 'date'],
        ];
    }
}
