<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SwitchAccountRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['return_to' => ['required', 'string', 'regex:~\A/t/[A-Za-z0-9_-]+\z~']];
    }
}
