<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ProfileController (company side)
//  Location: app/Http/Controllers/App/ProfileController.php
// ══════════════════════════════════════════════════════════════════
class ProfileController extends Controller
{
    public function index(): Response
    {
        $company = auth()->user()->company;

        return Inertia::render('App/Profile/Index', [
            'user' => auth()->user()->only(['id', 'name', 'email', 'language', 'theme']),
            'businessTypes' => $company?->businessTypes() ?? ['trading'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Change your own password, and end every other session.
     *
     * The second half is the point. Somebody changing their password
     * has usually just decided their account may be compromised —
     * and until now that did nothing to whoever else was signed in:
     * the attacker's session stayed valid, so the one action a
     * worried user knows to take was the one that did not help.
     *
     * ORDER MATTERS, and not in the direction it first looks.
     * logoutOtherDevices() verifies the password it is given against
     * the CURRENTLY STORED hash before re-hashing the session
     * marker — so it has to run AFTER the new password is saved,
     * with the new password. Called before, it throws
     * "The given password does not match the current password" and
     * the whole request 500s.
     *
     * It also only does anything because AuthenticateSession is
     * registered on this route group (see bootstrap/app.php). That
     * middleware is what compares each request's session marker
     * against the user's current hash; without it this call
     * re-hashes a marker nothing ever reads, and every other session
     * carries on exactly as before.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'          => ['required', 'confirmed', PasswordRules::defaults()],
        ]);

        $request->user()->update(['password' => $data['password']]);

        Auth::logoutOtherDevices($data['password']);

        // A fresh session id on top, so the one credential an
        // attacker could still be holding — this session's own
        // cookie — stops working too.
        $request->session()->regenerate();

        return back()->with('success', 'Password updated. You have been signed out everywhere else.');
    }

    /**
     * Company-admin only — lets a company that predates this
     * feature (or just changed what it does) switch Service /
     * Trading / Production on or off later. Nothing about existing
     * Items, Sales, or Purchases is touched by this — it only
     * changes which screens/tabs show up going forward (see the
     * frontend's useBusinessType composable).
     */
    public function updateBusinessTypes(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isCompanyAdmin(), 403);

        $data = $request->validate([
            'business_types'   => ['required', 'array', 'min:1'],
            'business_types.*' => [Rule::in(['service', 'trading', 'production'])],
        ]);

        $request->user()->company->update(['business_types' => $data['business_types']]);

        return back()->with('success', 'Business type updated.');
    }
}

