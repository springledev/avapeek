<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates the `children` upload field: a JSON object mapping child id to a
 * wrapped photo key `{ encrypted_pek, iv_pek }`. Shape only; the server never
 * inspects key material.
 */
class PekWrap implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded) || $decoded === []) {
            $fail('At least one child is required.');

            return;
        }

        foreach ($decoded as $childId => $wrap) {
            if (! is_numeric($childId) || ! is_array($wrap)) {
                $fail('The :attribute field is malformed.');

                return;
            }

            foreach (['encrypted_pek', 'iv_pek'] as $field) {
                if (! isset($wrap[$field]) || ! is_string($wrap[$field]) || $wrap[$field] === '' || strlen($wrap[$field]) > 512) {
                    $fail('The :attribute field is malformed.');

                    return;
                }
            }
        }
    }
}
