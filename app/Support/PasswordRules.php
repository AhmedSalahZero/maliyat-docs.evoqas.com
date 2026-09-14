<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * Project-wide password rules (registration, reset, profile).
     */
    public static function defaults(): Password
    {
        return Password::min(8)
            ->letters()
            ->numbers();
    }
}
