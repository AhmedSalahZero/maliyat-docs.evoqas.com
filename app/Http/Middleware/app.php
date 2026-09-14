<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\SetLocale;
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
