<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads auth_verification config (driven by .env), and remembers
 * which account is currently waiting to be verified.
 */
final class AuthVerification
{
    /**
     * Where the pending address lives between requests.
     *
     * This has to be ordinary session data, not flashed data. It used
     * to be flashed — `redirect()->with('email', ...)` — which
     * survives exactly one request. So the verification screen knew
     * the address the first time it rendered and never again: a
     * refresh, or pressing "Resend code" (which redirects back, and
     * that is a fresh request), left the page with no address at all.
     *
     * The visible symptom was the Resend button being permanently
     * unclickable, because it disables itself when there is no
     * address to send to. The address is also what the code entry
     * itself is checked against, so the whole screen was one refresh
     * away from being unusable.
     *
     * Nobody is signed in at this point — registration deliberately
     * does not log anyone in until they are verified — so there is no
     * authenticated user to fall back on. The session is the only
     * place that knows.
     */
    private const PENDING_EMAIL_KEY = 'verification.pending_email';

    public static function enabled(): bool
    {
        return (bool) config('auth_verification.enabled');
    }

    public static function sendOnRegister(): bool
    {
        return static::enabled() && (bool) config('auth_verification.send_on_register');
    }

    /**
     * Remember the account waiting for a code.
     *
     * Called from every route that sends somebody to the
     * verification screen, so it does not matter which door they
     * came through — signing up, or signing in with an account that
     * was never verified.
     */
    public static function rememberPendingEmail(Request $request, string $email): void
    {
        $request->session()->put(self::PENDING_EMAIL_KEY, $email);
    }

    /**
     * Which account the verification screen is for.
     *
     * In priority order: whoever is signed in (an existing user
     * verifying a changed address), then the account we were told
     * about on the way here, then an address in the URL — that last
     * one is what makes a link from an email work.
     */
    public static function pendingEmail(Request $request): ?string
    {
        return $request->user()?->email
            ?? $request->session()->get(self::PENDING_EMAIL_KEY)
            ?? $request->session()->get('email') // legacy flashed key
            ?? $request->query('email');
    }

    /**
     * Drop it once there is nothing left to verify — the session
     * should not keep pointing at an account that is already done.
     */
    public static function forgetPendingEmail(Request $request): void
    {
        $request->session()->forget(self::PENDING_EMAIL_KEY);
    }
}
