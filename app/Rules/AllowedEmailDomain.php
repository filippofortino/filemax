<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

final class AllowedEmailDomain implements ValidationRule
{
    public static function allows(string $email): bool
    {
        $domains = config()->array('filemax.allowed_email_domains');

        return $domains === [] || in_array(Str::afterLast(Str::lower(mb_trim($email)), '@'), $domains, true);
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value)) {
            $fail('The :attribute must use an allowed email domain.');
        }
    }
}
