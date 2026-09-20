<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — NoStoreForAuthenticated
//  Location: app/Http/Middleware/NoStoreForAuthenticated.php
//
//  Marks every signed-in response as un-storable.
//
//  Every page this app serves carries its whole Inertia payload
//  inline in the HTML (the @inertia directive in
//  resources/views/app.blade.php) — the signed-in user, their
//  company, and all of that page's figures. Anything that keeps a
//  copy of that HTML and replays it to a later request is handing
//  one company's books to whoever asks next, because the copy is
//  keyed on the URL and the URL says nothing about who was signed
//  in.
//
//  That is not hypothetical: it was reported from production, where
//  a service worker was caching navigations for 24 hours and serving
//  one company's dashboard to another company's admin. That cache is
//  gone (see vite.config.js) and is cleared on sign-out (see
//  resources/js/composables/usePrivateCache.js). This middleware
//  closes the same hole for every OTHER cache in the path that
//  nobody controls from here: the browser's ordinary HTTP cache, the
//  back/forward buffer, a corporate proxy, a CDN or a hosting-panel
//  page cache switched on later by someone who never read this file.
//
//  Worth being precise about one limitation, so this is not
//  mistaken for the whole fix: the Cache Storage API that service
//  workers use IGNORES these headers entirely. A service worker can
//  still store a no-store response. Headers are the backstop; not
//  caching in the worker is the fix.
//
//  Guest responses are deliberately left alone — the login and
//  marketing pages are identical for everybody and benefit from
//  being cached normally.
// ══════════════════════════════════════════════════════════════════
class NoStoreForAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->user()) {
            return $response;
        }

        // no-store is the only directive that actually forbids
        // writing a copy down; private and no-cache each still allow
        // a stored copy under some conditions. All three are sent
        // because intermediaries vary in which ones they honour.
        $response->headers->set('Cache-Control', 'no-store, no-cache, private, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
