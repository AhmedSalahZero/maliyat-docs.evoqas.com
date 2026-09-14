<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EnsureAdmin Middleware
//  (kept its InPractice filename/alias — 'admin' — to avoid touching
//  bootstrap/app.php; in this app it means "platform super_admin":
//  the person who onboards companies, not a company's own admin
//  user — see App\Enums\UserRole for the three roles.)
//
//  Protects every /admin/* route. Applied AFTER the 'auth' middleware.
//
//  If the user is not a super_admin: 403, no redirect to a "real"
//  admin login (there isn't one — everyone signs in the same way).
// ══════════════════════════════════════════════════════════════════

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if (! auth()->user()->isSuperAdmin()) {
            abort(403, __('errors.forbidden'));
        }

        return $next($request);
    }
}
