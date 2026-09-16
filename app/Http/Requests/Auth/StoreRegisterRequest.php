<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — StoreRegisterRequest
//  Location: app/Http/Requests/Auth/StoreRegisterRequest.php
//
//  Public sign-up is company sign-up: the person filling this form
//  becomes the first company_admin of a brand-new Company. Employee
//  accounts are never created here — a company_admin creates those
//  from inside the app (see App\Http\Controllers\App\UserController).
//
//  Two anti-bot guards, neither of which a person can see: a
//  honeypot field (_hp), and a minimum time between the form being
//  served and coming back. The timing half is measured entirely on
//  the server — see withValidator() for what went wrong when it was
//  not.
//  Dropped from InPractice: nickname, hubs, profession, experience
//  level — none of those apply to a bookkeeping company account.
// ══════════════════════════════════════════════════════════════════
class StoreRegisterRequest extends FormRequest
{
    /**
     * When the registration form was rendered, stamped by the server.
     *
     * Written by RegisteredUserController::create(). Read here.
     * Nothing about it travels through the browser, which is the
     * whole point.
     */
    public const FORM_OPENED_AT = 'register.form_opened_at';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ── Security fields ────────────────────────────────
            //
            // Only the honeypot travels with the form now. The
            // "how long did this take" guard used to send a
            // browser timestamp (_ft) and compare it against the
            // server's clock — see withValidator() for why that had
            // to go.
            '_hp' => ['present', 'max:0'],

            // ── Company ─────────────────────────────────────────
            'company_name' => ['required', 'string', 'min:2', 'max:150'],
            'currency'     => ['nullable', 'string', 'max:8'],
            // "What kind of business is this?" — Service / Trading /
            // Production, pick any (min 1). Drives which screens the
            // company sees — see Company::businessTypes().
            'business_types'   => ['required', 'array', 'min:1'],
            'business_types.*' => ['string', 'in:service,trading,production'],

            // ── First user (becomes company_admin) ──────────────
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRules::defaults()],

            // ── Preferences ──────────────────────────────────────
            'language' => ['nullable', 'string', 'in:en,ar'],
        ];
    }

    /** A form completed faster than this was not filled in by hand. */
    private const MINIMUM_SECONDS = 3;

    /**
     * Refuse a submission that arrived impossibly fast.
     *
     * Both halves of this were wrong.
     *
     * THE CLOCK. It used to send a timestamp from the browser
     * (Date.now()) and subtract it from the server's clock. Those are
     * two different machines, and a device whose clock runs even a
     * minute ahead produced an "elapsed" time of zero or less — so a
     * real customer who spent five minutes on the form was rejected
     * for filling it in too fast. Device clocks drift; a phone set by
     * hand can be minutes out. Now the page-open moment is stamped on
     * the SERVER when the form is rendered, and compared against the
     * server's own clock, so there is only one clock involved and no
     * skew is possible.
     *
     * THE MESSAGE. It reported __('auth.failed') — "These credentials
     * do not match our records" — which is the SIGN-IN error, on a
     * form where nothing is being matched against anything. A person
     * creating their first account was told their credentials were
     * wrong.
     *
     * A missing stamp is allowed through rather than refused. CSRF
     * has already established that this submission came from a real
     * session on a page we served; a session that lost one key is far
     * more likely to be a real person than a bot, and the honeypot is
     * what actually catches those.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $openedAt = $this->session()->get(self::FORM_OPENED_AT);

            if (! $openedAt) {
                return;
            }

            if (now()->diffInSeconds($openedAt, absolute: true) < self::MINIMUM_SECONDS) {
                $validator->errors()->add('_hp', __('auth.blocked_submission'));
            }
        });
    }

    public function messages(): array
    {
        return [
            // The anti-bot fields are hidden, so an error attached to
            // them has nowhere on the form to appear. A person who
            // trips one — a browser autofilling the honeypot — would
            // press Create
            // Account and watch absolutely nothing happen, forever.
            //
            // The text is deliberately vague about WHICH guard fired:
            // telling a bot exactly which field gave it away is the
            // one thing these fields exist to avoid. Register.vue
            // surfaces it as a banner above the form — see the
            // unboundError computed there.
            '_hp.present'         => __('auth.blocked_submission'),
            '_hp.max'             => __('auth.blocked_submission'),
            'password.confirmed'  => __('auth.password_confirmed'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_name' => trim($this->company_name ?? ''),
            'name'         => trim($this->name ?? ''),
            'email'        => strtolower(trim($this->email ?? '')),
            'language'     => $this->language ?? 'en',
            'currency'     => $this->currency ?: 'EGP',
        ]);
    }
}
