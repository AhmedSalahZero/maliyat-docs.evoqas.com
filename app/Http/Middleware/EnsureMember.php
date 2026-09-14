<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EnsureMember Middleware
//  (kept its InPractice filename/alias — 'member' — to avoid touching
//  bootstrap/app.php; in this app it means "company user": a
//  company_admin or employee, i.e. anyone who works inside a company's
//  books, as opposed to the platform's super_admin.)
//
//  Protects every /app/* route. Applied AFTER 'auth' and 'verified'.
//
//  Checks:
//    1. The account is active (a company_admin can deactivate an
//       employee; a super_admin can deactivate a whole company's
//       users). Inactive users are logged out immediately.
//    2. The user actually belongs to a company (company_id is set).
//       A super_admin has no company_id and manages companies from
//       the separate /admin area instead — so they're redirected
//       there rather than let into /app with nothing to see.
// ══════════════════════════════════════════════════════════════════

class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Checked on every request, not just at login, so revoking a
        // user or deactivating their company takes effect immediately
        // for anyone already holding a session. Same rule as
        // LoginRequest — see User::accessDenialReason().
        if ($denialReason = $user->accessDenialReason()) {
            auth()->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors([
                    'email' => __($denialReason),
                ]);
        }

        // Super admins manage companies from /admin, not /app.
        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        // A company_admin/employee with no company_id is a broken
        // account state (shouldn't normally happen) — fail safe.
        if (! $user->company_id) {
            abort(403, __('errors.forbidden'));
        }

        return $next($request);
    }
}
