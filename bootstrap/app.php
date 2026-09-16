<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureBusinessType;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\PostDueDepreciation;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackDailyUserAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ── Inertia shared middleware ──────────────────────────
        // Required for Inertia.js to work correctly
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // ── SetLocale runs on every web request ────────────────
        // Must be early so all subsequent code uses correct locale
        $middleware->web(append: [
            SetLocale::class,
        ]);

        // ── TrackDailyUserAccess runs on every web request ─────
        // Records the first authenticated GET/HEAD visit on a new
        // calendar day (login_count is incremented separately by
        // RecordSuccessfulLogin on explicit logins). The middleware
        // itself guards on auth()->check(), method, and asset paths,
        // so it's safe to run globally for guests and assets too.
        $middleware->web(append: [
            TrackDailyUserAccess::class,
        ]);

        // ── PostDueDepreciation runs on every web request ──────
        // Posts any depreciation the viewer's company owes, on the
        // first page it opens each day. This replaces the cron
        // dependency: depreciation used to run only if the server
        // had a crontab line calling schedule:run, and if that line
        // was missing it silently never posted at all.
        //
        // The work happens in terminate(), after the response has
        // gone out, so the user never waits for it. On every request
        // but the day's first it is a single date comparison against
        // data HandleInertiaRequests has already loaded.
        $middleware->web(append: [
            PostDueDepreciation::class,
        ]);

        // ── AuthenticateSession ────────────────────────────────
        //
        // Compares each request's session marker against the user's
        // current password hash, and signs the session out if they
        // no longer agree.
        //
        // This is what gives Auth::logoutOtherDevices() any effect at
        // all. Without it that call re-hashes a marker nothing reads,
        // so "change your password" — the one action somebody takes
        // when they believe their account is compromised — left every
        // other session, including the attacker's, working exactly as
        // before.
        //
        // Registered as an alias rather than globally: it only means
        // anything for an authenticated request, and the auth route
        // groups are where those are.
        $middleware->alias([
            'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);

        // ── Register named middleware aliases ──────────────────
        // These names are used in route files:
        //   Route::middleware(['auth', 'admin'])
        //   Route::middleware(['auth', 'verified', 'member'])
        $middleware->alias([
            'admin'  => EnsureAdmin::class,
            'member' => EnsureMember::class,

            // Applied to the /app and /admin route groups (see
            // routes/web.php). Not global: a failed login retried
            // with the same credentials is a legitimate repeat, and
            // this would refuse it.
            'no-duplicate' => PreventDuplicateSubmission::class,

            // Gates a screen on what the company actually does —
            // used as `business-type:production`. The Production
            // screens were previously gated only by the frontend
            // deciding not to draw a menu entry.
            'business-type' => EnsureBusinessType::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── Rate limiting has to be VISIBLE ────────────────────
        //
        // Every auth route is throttled, which is right — but a
        // throttled request returns a bare 429 HTML error page, and
        // that is not an Inertia response. The page the person is
        // looking at therefore does nothing at all: no message, no
        // movement, no explanation. They try again, which burns
        // another attempt, which extends the lockout.
        //
        // Registration is where this bites hardest. Somebody whose
        // first attempt failed for any reason retries a few times,
        // silently exhausts five attempts a minute, and from then on
        // the form is dead in a way that looks identical to the
        // original fault — so they cannot tell whether the problem
        // was fixed.
        //
        // Sent back as a normal validation error instead, which the
        // form already knows how to display, and carrying the wait
        // so the message is actionable rather than just apologetic.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return null; // let the framework answer a plain request
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return back()->withErrors([
                'email' => __('auth.throttle_requests', ['seconds' => $seconds]),
            ]);
        });
    })
    ->create();