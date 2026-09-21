<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class TeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Team::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['user_id' => ['required', 'uuid', Rule::exists(User::class, 'id')]];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('user_id') && ! User::query()->findOrFail($this->string('user_id')->toString())->isEligible()) {
                $validator->errors()->add('user_id', 'Choose a registered Mediamax account.');
            }
        }];
    }
}
