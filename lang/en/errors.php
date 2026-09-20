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

    // An account whose company no longer exists. It cannot be let in:
    // a null company_id switches the tenant scope off rather than
    // narrowing it — see User::accessDenialReason().
    'account_orphaned' => 'This account is no longer linked to a company. Please contact support.',

    // Shown when the whole company has been switched off by a super
    // admin, or its subscription period has ended.
    'company_suspended' => 'Your company account is not active. Please contact support to reactivate it.',

    // Shown when the subscription window has lapsed — distinct from
    // a manual deactivation so the user knows renewal is the fix.
    'subscription_expired' => 'Your subscription has ended. Please renew to regain access to your account.',

    // Shown when a receipt/payment would settle more than the
    // invoice or bill it is pointed at still owes — see
    // App\Http\Requests\Concerns\GuardsPaymentAmount.
    'payment_exceeds_balance' => 'This is more than the remaining balance of :remaining. Reduce the amount, or record the extra as a separate receipt.',

    // Shown when a document's lines add up past what any money
    // column can hold — see GuardsDocumentTotal.
    'total_too_large' => 'The total of these lines is larger than the maximum of :max.',

    // Shown when "paid now" on a partial exceeds the document.
    'paid_now_exceeds_total' => 'Paid now cannot be more than the total of :total.',

    // Shown when the same form is submitted twice in quick
    // succession — see App\Http\Middleware\PreventDuplicateSubmission.
    'duplicate_submission' => 'That looks like the same entry you just saved, so it was not recorded again. Check the list below — if you really meant to enter it twice, try again in a moment.',

    // Shown when a sale line would take an item below zero stock
    // — see App\Http\Requests\Concerns\GuardsStockLevels.
    'insufficient_stock' => 'Only :available :unit of ":item" are in stock. Record the purchase first, or reduce the quantity.',

    // Shown when a destructive action is attempted by an employee
    // — deleting a record is a company-admin action.
    'delete_requires_admin' => 'Only a company administrator can delete a financial record.',

    // Role/tenant mismatch — a member reaching an admin route, or a
    // user with no company reaching the app area.
    'forbidden' => 'You do not have permission to access this page.',

];
