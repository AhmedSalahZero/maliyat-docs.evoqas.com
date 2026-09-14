<?php

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Email Strings (English)
//
//  Every key here is referenced from resources/views/emails/*.blade.php
//  or from app/Notifications/*.php. A missing key is not a silent
//  failure — Laravel renders the raw key ("emails.verify_code.heading")
//  straight into the delivered email, so keep this file in sync with
//  lang/ar/emails.php key-for-key.
// ══════════════════════════════════════════════════════════════════

return [

    // ── Email verification code ───────────────────────────────────
    'verify_code' => [
        'subject'     => 'Your Maliyat Docs verification code',
        'heading'     => 'Confirm your email address',
        'greeting'    => 'Hi :name,',
        'intro'       => 'Use the code below to finish setting up your Maliyat Docs account.',
        'expire'      => 'This code expires in :count minutes.',
        'instruction' => 'Enter it on the verification screen to activate your account.',
        'ignore'      => "If you didn't create this account, you can safely ignore this email.",
        'closing'     => 'Welcome aboard.',
    ],

    // ── Password reset ────────────────────────────────────────────
    'reset_password' => [
        'subject'  => 'Reset your Maliyat Docs password',
        'heading'  => 'Reset your password',
        'greeting' => 'Hi :name,',
        'intro'    => 'We received a request to reset the password for your Maliyat Docs account.',
        'button'   => 'Reset password',
        'expire'   => 'This link expires in :count minutes.',
        'fallback' => "If the button doesn't work, copy and paste this link into your browser:",
        'ignore'   => 'If you did not request a password reset, no action is needed — your password stays unchanged.',
        'closing'  => 'Thanks.',
    ],

    // ── Shared layout ─────────────────────────────────────────────
    'footer_tagline' => 'Bookkeeping, kept simple.',

];
