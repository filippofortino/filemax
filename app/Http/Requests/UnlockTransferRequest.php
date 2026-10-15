<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class UnlockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transfer = $this->route('transfer');
        abort_unless($transfer instanceof Transfer, 404);
        abort_unless($transfer->isAvailable(), 410);

        return $transfer->visibility === 'public' && $transfer->password_hash !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'password' => [
                'bail',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (mb_strlen($value, '8bit') > 72 || str_contains($value, "\0"))) {
                        $fail('The password must not exceed 72 bytes or contain null characters.');
                    }
                },
            ],
        ];
    }
}
