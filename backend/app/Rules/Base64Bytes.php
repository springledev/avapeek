<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Strict standard-base64 string that decodes to exactly the given number of bytes
 * and is in canonical form (no whitespace, no stray padding bits). A string made only of hex
 * digits is rejected: a hex wrap of the right length is also valid base64 but is never what
 * the client produces (the odds of a random wrap being all-hex are negligible).
 */
class Base64Bytes implements ValidationRule
{
    public function __construct(private readonly int $bytes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $expectedLength = intdiv($this->bytes + 2, 3) * 4;

        if (! is_string($value)
            || strlen($value) !== $expectedLength
            || ctype_xdigit($value)
            || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $value) !== 1) {
            $fail('The :attribute field is malformed.');

            return;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || strlen($decoded) !== $this->bytes || base64_encode($decoded) !== $value) {
            $fail('The :attribute field is malformed.');
        }
    }
}
