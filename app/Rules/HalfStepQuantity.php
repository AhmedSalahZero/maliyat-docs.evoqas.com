<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// ══════════════════════════════════════════════════════════════════
//  Whole or half quantities only: 1, 1.5, 2, 2.5 …
//
//  Used on Inventory Purchase lines (owner's decision, Sep 2026):
//  you can buy one and a half cartons, but not 1.25 or 1.3.
//  Checked by doubling the number — a whole or half quantity
//  doubles to a whole number. The tiny tolerance absorbs float
//  noise (1.5 arriving as 1.4999999999).
// ══════════════════════════════════════════════════════════════════
class HalfStepQuantity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return; // "numeric" already reports this one
        }

        $doubled = (float) $value * 2;

        if (abs($doubled - round($doubled)) > 0.000001) {
            $fail('validation.half_step_qty')->translate();
        }
    }
}
