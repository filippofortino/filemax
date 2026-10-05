<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountSettingsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'show_name_on_transfers' => ['sometimes', 'boolean'],
            'notify_transfer_expiring' => ['sometimes', 'boolean'],
            'notify_transfer_downloaded' => ['sometimes', 'boolean'],
        ];
    }
}
