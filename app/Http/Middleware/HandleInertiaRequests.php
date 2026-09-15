<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — HandleInertiaRequests
//  Location: app/Http/Middleware/HandleInertiaRequests.php
//
//  Shares data with every Inertia page (available as `$page.props`
//  in every .vue component without each controller passing it).
//
//  Rewritten for MaliyatDocs — the InPractice version shared
//  nickname/profession/experience_level/hub membership and read
//  from a `notifications` table that was never migrated here.
//  This version shares only what this app's pages actually use:
//  the user's role/company context, their theme + locale, and
//  flashed status messages.
// ══════════════════════════════════════════════════════════════════
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'csrf_token' => fn () => csrf_token(),

            'auth' => fn () => $this->resolveAuth($request),

            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info'    => $request->session()->get('info'),
            ],

            'locale' => app()->getLocale(),

            'translations' => [
                'auth' => Lang::get('auth'),
            ],

            // Who to contact to renew. Read by the trial countdown in
            // AppLayout — there is no self-service billing page, so a
            // banner saying "your access ends in 3 days" has to say
            // who to talk to or it is a dead end. Either key may be
            // null; the banner renders whichever exists.
            'support' => [
                'email' => config('subscription.support_email'),
                'phone' => config('subscription.support_phone'),
            ],
        ];
    }

    /**
     * @return array{user: ?array<string, mixed>}
     */
    private function resolveAuth(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return ['user' => null];
        }

        return [
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'company_id' => $user->company_id,
                'company'    => $user->company_id
                    ? $this->resolveCompany($user)
                    : null,
                'theme'       => $user->theme ?? 'light',
                'locale'      => $user->language,
                'language'    => $user->language,
                'is_active'   => $user->is_active,
                'login_count' => (int) $user->login_count,
            ],
        ];
    }

    /**
     * The company as the frontend needs it, including how much of the
     * free trial is left.
     *
     * `trial_days_left` is what drives the countdown banner in
     * AppLayout. It is null for a company with no expiry (one that
     * has paid), so the banner simply never renders for them.
     */
    private function resolveCompany(\App\Models\User $user): ?array
    {
        $company = $user->company;

        if (! $company) {
            return null;
        }

        return [
            ...$company->only(['id', 'name', 'name_ar', 'currency']),
            'trial_ends_at'   => $company->trial_ends_at?->toDateString(),
            'trial_days_left' => $company->daysUntilExpiry(),
            'trial_expiring'  => $company->isExpiringSoon(),
        ];
    }
}
