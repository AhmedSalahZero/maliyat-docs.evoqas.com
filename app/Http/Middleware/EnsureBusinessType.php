<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EnsureBusinessType
//  Location: app/Http/Middleware/EnsureBusinessType.php
//
//  Gates a screen on what the company actually does — Service,
//  Trading, Production (see Company::businessTypes(), set at sign-up
//  and changeable from Profile).
//
//  Used as `business-type:production` in routes/web.php.
//
//  Why this exists: the Production screens were reachable by anyone
//  who knew the URL. The gate was the frontend's businessType
//  composable deciding which menu entries to draw — a UI convention,
//  not a rule. The controller's own doc comment admitted it: "there
//  is no server-side gate beyond that today."
//
//  In practice nothing much could be done through it, because the
//  form requests demand items typed 'product' and 'raw_material'
//  which a non-production company has none of. But that is an
//  accident of the data, not a decision: the moment a company turns
//  Production on and back off, or an item is typed by hand, the
//  accident stops protecting anything. A rule the server enforces
//  does not depend on what happens to exist.
//
//  A company that has not enabled the feature gets 404 rather than
//  403 — 403 confirms the screen is there and they are merely not
//  allowed on it, which is more than they need to know about a
//  feature that is not part of their account.
// ══════════════════════════════════════════════════════════════════
class EnsureBusinessType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $company = $request->user()?->company;

        abort_unless($company?->hasBusinessType($type), 404);

        return $next($request);
    }
}
