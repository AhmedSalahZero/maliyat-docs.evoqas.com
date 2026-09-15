<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationCodeRequest;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Services\Auth\EmailVerificationService;
use App\Support\AuthVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class VerifyEmailCodeController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verificationService
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! AuthVerification::enabled()) {
            return redirect()->route('login');
        }

        if ($request->user()?->hasVerifiedEmail()) {
            return $request->user()->isSuperAdmin()
                ? redirect()->route('admin.dashboard')
                : redirect()->route('app.dashboard');
        }

        $email = AuthVerification::pendingEmail($request);

        return Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
            'email'  => $email,
        ]);
    }

    public function store(VerifyEmailCodeRequest $request): RedirectResponse
    {
        if (! AuthVerification::enabled()) {
            return redirect()->route('login');
        }

        $user = $request->resolveUser();

        $this->verificationService->verify($user, $request->validated('code'));

        Auth::login($user);
        $request->session()->regenerate();

        // Nothing left to verify — the session should stop pointing
        // at this account.
        AuthVerification::forgetPendingEmail($request);

        return redirect()
            ->route('app.dashboard')
            ->with('status', 'email-verified');
    }

    public function resend(ResendVerificationCodeRequest $request): RedirectResponse
    {
        if (! AuthVerification::enabled()) {
            return redirect()->route('login');
        }

        $user = $request->resolveUser();

        $this->verificationService->issueAndSend($user);

        // back() is a fresh request, so the screen it returns to has
        // to be able to find the address again.
        AuthVerification::rememberPendingEmail($request, $user->email);

        return back()->with('status', 'verification-code-sent');
    }
}
