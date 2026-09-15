<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\PostDueDepreciation;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackDailyUserAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();