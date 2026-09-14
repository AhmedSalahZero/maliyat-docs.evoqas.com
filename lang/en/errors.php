<?php

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Error Strings (English)
//
//  Referenced from the auth middleware and LoginRequest. These are
//  shown directly to the user, so they explain what happened and
//  what to do next rather than just naming the failure.
// ══════════════════════════════════════════════════════════════════

return [

    // Shown when an individual user account has been switched off by
    // a company admin (App\Http\Controllers\App\UserController).
    'account_suspended' => 'This account has been deactivated. Please contact your company administrator.',

    // Shown when the whole company has been switched off by a super
    // admin, or its subscription period has ended.
    'company_suspended' => 'Your company account is not active. Please contact support to reactivate it.',

    // Shown when the subscription window has lapsed — distinct from
    // a manual deactivation so the user knows renewal is the fix.
    'subscription_expired' => 'Your subscription has ended. Please renew to regain access to your account.',

    // Role/tenant mismatch — a member reaching an admin route, or a
    // user with no company reaching the app area.
    'forbidden' => 'You do not have permission to access this page.',

];
