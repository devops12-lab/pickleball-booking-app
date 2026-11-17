<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures the name contains at least two words (first name and surname).
 */
class FullName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $words = preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) < 2) {
            $fail('The :attribute must include a first name and surname (at least 2 words).');
        }
    }
}
