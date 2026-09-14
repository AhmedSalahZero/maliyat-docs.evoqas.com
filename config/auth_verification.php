<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email verification (OTP) — master switches
    |--------------------------------------------------------------------------
    |
    | AUTH_EMAIL_VERIFICATION_ENABLED
    |   true  → users must verify via code before login / member routes
    |   false → accounts are marked verified on registration (no OTP)
    |
    | AUTH_EMAIL_VERIFICATION_SEND_ON_REGISTER
    |   true  → send OTP email right after sign-up (when enabled above)
    |   false → no automatic email; user can request a code on verify page
    |
    */

    'enabled' => filter_var(
        env('AUTH_EMAIL_VERIFICATION_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    'send_on_register' => filter_var(
        env('AUTH_EMAIL_VERIFICATION_SEND_ON_REGISTER', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Email verification code (OTP) — timing & limits
    |--------------------------------------------------------------------------
    */

    'code_length' => (int) env('AUTH_VERIFICATION_CODE_LENGTH', 6),

    'expires_minutes' => (int) env('AUTH_VERIFICATION_CODE_EXPIRES', 15),

    'max_attempts' => (int) env('AUTH_VERIFICATION_MAX_ATTEMPTS', 5),

    'resend_throttle_seconds' => (int) env('AUTH_VERIFICATION_RESEND_THROTTLE', 60),

];
