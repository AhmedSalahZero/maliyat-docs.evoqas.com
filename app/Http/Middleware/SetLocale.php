<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  SetLocale Middleware
//
//  Runs on every request.
//  Sets the application language based on:
//    1. Logged-in user's saved language preference (en or ar)
//    2. Session language (if user switched mid-session)
//    3. Default fallback: English
//
//  This ensures every page, every error message, every translated
//  string appears in the correct language for that user.
// ══════════════════════════════════════════════════════════════════

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── Priority 1: Logged-in user's saved preference ──────
        if (auth()->check()) {
            $locale = auth()->user()->language;

            // Keep session in sync with user preference
            Session::put('locale', $locale);
            App::setLocale($locale);

            return $next($request);
        }

        // ── Priority 2: Session (guest switched language) ──────
        if (Session::has('locale')) {
            $locale = Session::get('locale');

            // Only allow valid locales — never trust raw session data
            if (in_array($locale, ['en', 'ar'])) {
                App::setLocale($locale);
                return $next($request);
            }
        }

        // ── Priority 3: Default fallback ───────────────────────
        App::setLocale(config('app.locale', 'en'));

        return $next($request);
    }
}
