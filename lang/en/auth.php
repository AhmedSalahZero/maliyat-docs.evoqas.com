<?php

return [
    'login'                => 'Log In',
    'logout'               => 'Log Out',
    'register'             => 'Register',
    'email'                => 'Email Address',
    'password'             => 'Password',
    'password_confirm'     => 'Confirm Password',
    'remember_me'          => 'Remember Me',
    'forgot_password'      => 'Forgot your password?',
    'reset_password'       => 'Reset Password',
    'send_reset_link'      => 'Send Reset Link',
    'already_registered'   => 'Already registered?',
    'no_account'           => 'Don\'t have an account?',

    // ── Registration ──────────────────────────────────────────
    'full_name'            => 'Full Name',
    'nickname'             => 'Nickname',
    'nickname_hint'        => 'This is your public name. It appears in the forum, cases, and documents instead of your real name.',
    'nickname_placeholder' => 'e.g. FinanceGuru, MarketingPro',
    'profession'           => 'Your Profession',
    'experience_level'     => 'Experience Level',
    'select_hub'           => 'Select Your Hub(s)',
    'select_hub_hint'      => 'Choose the professional areas you want to join. You can join more than one.',
    'language_preference'  => 'Preferred Language',

    // ── Verification ──────────────────────────────────────────
    'verify_email'         => 'Verify Email Address',
    'verify_email_sent'    => 'A verification link has been sent to your email address.',
    'verify_email_check'   => 'Before continuing, please check your email for a verification link.',
    'verify_resend'        => 'Resend Verification Email',
    'verify_resend_sent'   => 'A new verification link has been sent to your email address.',

    // ── Account Status ────────────────────────────────────────
    'account_suspended'    => 'Your account has been suspended. Please contact support.',
    'account_inactive'     => 'Your account is not active. Please contact support.',

    // ── Failed Messages ───────────────────────────────────────
    'blocked_submission' => 'Something blocked that submission. Please reload the page and try again.',

    'failed'               => 'These credentials do not match our records.',
    // Used for ANY throttled auth route, not just sign-in — see the
    // ThrottleRequestsException handler in bootstrap/app.php.
    'throttle_requests' => 'Too many attempts. Please wait :seconds seconds and try again.',

    'throttle'             => 'Too many login attempts. Please try again in :seconds seconds.',
    'password_incorrect'   => 'The provided password is incorrect.',

    // ── Password Reset ────────────────────────────────────────
    'reset_link_sent'      => 'We have sent a password reset link to your email.',
    'password_reset_done'  => 'Your password has been reset successfully.',
    'new_password'         => 'New Password',
    'confirm_new_password' => 'Confirm New Password',
    'reset_email_not_found' => 'We could not find an account with that email address.',
    'password_confirmed'    => 'Password confirmed.',

    // ── Verification Codes (OTP) ──────────────────────────────
    // Referenced from App\Services\Auth\EmailVerificationService and
    // the verify-email controllers. Each names what the user should
    // do next rather than only what went wrong.
    'registration_complete'        => 'Your account has been created. Enter the code we emailed you to finish signing in.',
    'verification_code_invalid'    => 'That code is not correct. Please check it and try again.',
    'verification_code_expired'    => 'That code has expired. Request a new one to continue.',
    'verification_code_locked'     => 'Too many incorrect attempts. Request a new code to continue.',
    'verification_already_verified' => 'This email address is already verified. You can log in normally.',
    'verification_email_not_found' => 'We could not find an account awaiting verification for that email address.',
];
