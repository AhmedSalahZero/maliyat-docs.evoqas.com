<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureMember;
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

        // ── Register named middleware aliases ──────────────────
        // These names are used in route files:
        //   Route::middleware(['auth', 'admin'])
        //   Route::middleware(['auth', 'verified', 'member'])
        $middleware->alias([
            'admin'  => EnsureAdmin::class,
            'member' => EnsureMember::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();