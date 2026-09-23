<?php

namespace App\Support;

use App\Rules\ContainsUppercaseLetter;
use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /**
     * Project-wide password rules (registration, reset, profile).
     *
     * NOTE (QA audit correction, Sep 2026): a breach-check via
     * ->uncompromised() was added here and then reverted. It calls
     * out to api.pwnedpasswords.com on every password submission,
     * and — confirmed against a real test run — that call does not
     * fail open when it can't complete (e.g. a machine with no
     * trusted SSL certificate bundle configured, a firewall, or the
     * API being briefly down): it throws, and blocks the submission
     * outright. Making sign-up and password changes depend on a
     * third-party service being reachable is a worse risk than the
     * weak-password problem it was meant to catch, so it was
     * removed. If this is revisited, it needs its own timeout and a
     * deliberate fallback that treats a failed check as "allow",
     * not Laravel's default behavior.
     */
    /**
     * Current rule (Sep 2026): at least 8 characters, with a letter,
     * at least one CAPITAL letter, a number and a symbol.
     * A small letter is deliberately NOT required — one capital is
     * enough (owner's decision), which is why ->mixedCase() is not
     * used here.
     *
     * Only applies when a password is set or changed; existing
     * passwords keep working at login.
     */
    public static function defaults(): Password
    {
        return Password::min(8)
            ->letters()
            ->numbers()
            ->symbols()
            ->rules([new ContainsUppercaseLetter]);
    }
}
