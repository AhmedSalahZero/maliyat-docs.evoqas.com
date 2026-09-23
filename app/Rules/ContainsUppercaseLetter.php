<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// ══════════════════════════════════════════════════════════════════
//  At least one capital (uppercase) letter.
//
//  Laravel's Password rule only offers ->mixedCase(), which demands
//  BOTH a capital and a small letter. The business rule here is
//  "one capital letter is enough", so this small rule is used
//  instead. Arabic letters have no capital form, so in practice the
//  user needs one English capital (A–Z); \p{Lu} also accepts other
//  scripts' capitals (É, Ä, …).
// ══════════════════════════════════════════════════════════════════
class ContainsUppercaseLetter implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\p{Lu}/u', $value)) {
            $fail('validation.password.uppercase')->translate();
        }
    }
}
