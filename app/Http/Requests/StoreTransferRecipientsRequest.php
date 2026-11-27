<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Transfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class StoreTransferRecipientsRequest extends FormRequest
{
    public const int MAX_EMAILS = 10;

    public function authorize(): bool
    {
        $transfer = $this->route('transfer');

        return $transfer instanceof Transfer && ($this->user()?->can('update', $transfer) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_EMAILS],
            'emails.*' => ['required', 'string', 'email:filter', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'emails.required' => 'Enter at least one email address.',
            'emails.max' => 'Send to up to '.self::MAX_EMAILS.' people at a time.',
            'emails.*.email' => '“:input” isn’t a valid email address.',
        ];
    }

    /** The dialog sends what was typed: addresses separated by commas, semicolons, spaces or new lines. */
    public function prepareForValidation(): void
    {
        $emails = $this->input('emails');
        if (is_string($emails)) {
            $emails = preg_split('/[\s,;]+/', $emails, flags: PREG_SPLIT_NO_EMPTY);
        }

        if (is_array($emails)) {
            $this->merge(['emails' => array_values(array_unique(array_map(
                fn (mixed $email): mixed => is_string($email) ? Str::lower(mb_trim($email)) : $email,
                $emails,
            ), SORT_REGULAR))]);
        }
    }
}
