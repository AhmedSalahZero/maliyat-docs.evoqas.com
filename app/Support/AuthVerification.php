<?php

namespace App\Support;

/**
 * Reads auth_verification config (driven by .env).
 */
final class AuthVerification
{
    public static function enabled(): bool
    {
        return (bool) config('auth_verification.enabled');
    }

    public static function sendOnRegister(): bool
    {
        return static::enabled() && (bool) config('auth_verification.send_on_register');
    }
}
