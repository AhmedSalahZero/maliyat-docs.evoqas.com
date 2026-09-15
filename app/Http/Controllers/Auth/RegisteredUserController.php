<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegisterRequest;
use App\Services\RegisterService;
use App\Support\AuthVerification;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — RegisteredUserController
//  Location: app/Http/Controllers/Auth/RegisteredUserController.php
//
//  Thin controller — follows the Project Bible strictly.
//
//  create() → returns the Register Vue page
//  store()  → receives validated request → calls RegisterService
//             → redirects to email verification (no login until verified)
//
//  NO business logic here. Everything lives in RegisterService.
// ══════════════════════════════════════════════════════════════════

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly RegisterService $registerService
    ) {}

    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * Validation is fully handled by StoreRegisterRequest —
     * including honeypot, time guard, nickname uniqueness, hub validity.
     * This method only orchestrates: validate → service → login → redirect.
     */
    public function store(StoreRegisterRequest $request): RedirectResponse
    {
        // All validation already passed in StoreRegisterRequest.
        // $request->validated() returns only the safe, validated fields.
        $user = $this->registerService->register(
            $request->validated()
        );

        if (! AuthVerification::enabled()) {
            return redirect()
                ->route('login')
                ->with('status', __('auth.registration_complete'));
        }

        // Do not log in until email is verified — redirect to OTP screen.
        //
        // The address is remembered in the session rather than only
        // flashed: flashed data is gone after one request, so the
        // verification screen knew who it was for the first time it
        // rendered and never again. See AuthVerification.
        AuthVerification::rememberPendingEmail($request, $user->email);

        $status = AuthVerification::sendOnRegister()
            ? 'verification-code-sent'
            : 'verify-email-check';

        return redirect()
            ->route('verification.notice')
            ->with('status', $status);
    }
}
