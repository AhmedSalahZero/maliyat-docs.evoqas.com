<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return Inertia::render('App/Profile/Index', [
            'user' => auth()->user()->only(['id', 'name', 'email', 'language', 'theme']),
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

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'          => ['required', 'confirmed', PasswordRules::defaults()],
        ]);

        $request->user()->update(['password' => $data['password']]);

        return back()->with('success', 'Password updated.');
    }
}
