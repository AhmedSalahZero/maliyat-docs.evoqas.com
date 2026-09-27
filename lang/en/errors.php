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
    'payment_exceeds_balance' => 'This is more than the remaining balance of :remaining. Reduce the amount — or, for a customer who paid more, tick "Keep the extra for this customer".',

    // Shown when a document's lines add up past what any money
    // column can hold — see GuardsDocumentTotal.
    'total_too_large' => 'The total of these lines is larger than the maximum of :max.',

    // Shown when "paid now" on a partial exceeds the document.
    'paid_now_exceeds_total' => 'Paid now cannot be more than the total of :total.',

    // Shown when the same form is submitted twice in quick
    // succession — see App\Http\Middleware\PreventDuplicateSubmission.
    'duplicate_submission' => 'That looks like the same entry you just saved, so it was not recorded again. Check the list below — if you really meant to enter it twice, try again in a moment.',

    // Stock can never end a day below zero — see
    // App\Services\StockTimeline and the GuardsStockLevels /
    // GuardsRawMaterialStock checks.
    //
    // Not enough on the document's own date:
    'insufficient_stock' => 'Only :available :unit of ":item" were in stock on :date. Record the purchase first (dated on or before :date), or check the date and quantity.',
    // Enough on the day, but a later sale or production run would
    // then be left without stock:
    'stock_needed_later' => 'This would leave ":item" short by :short :unit on :date, because that stock is needed by a later sale or production run. Record the purchase first, or check the date and quantity.',
    // Editing/deleting a purchase or production run, or resetting
    // opening stock, would take away stock that was already sold:
    'stock_already_used' => 'Not allowed: some of ":item" was already sold or used, so stock would be short by :short :unit on :date. Change or delete those sales or production runs first.',

    // Shown when a destructive action is attempted by an employee
    // — deleting a record is a company-admin action.
    'delete_requires_admin' => 'Only a company administrator can delete a financial record.',

    // Role/tenant mismatch — a member reaching an admin route, or a
    // user with no company reaching the app area.
    'forbidden' => 'You do not have permission to access this page.',


    'custody_already_settled' => 'This custody has already been settled, so it was not settled again. To change the settlement, delete the custody and record it again.',
    'opening_balance_not_a_bill' => 'Opening stock and equipment were already owned when you started using the app — there is nothing to pay for them. If you still owe a supplier, enter that under Opening Balance → Suppliers.',
    'ob_reset_has_payments' => 'The opening balance cannot be cleared yet: :count real payment(s) have been recorded against it since (the first is :amount on :date). Clearing now would delete that money from your cash and bank. Remove those payments from the Payments screen first, then clear.',
    'ob_accumulated_depreciation_too_high' => 'Depreciation already taken cannot be more than what the equipment cost.',
    'credit_needs_invoice' => 'Customer credit can only be used to pay one of that customer\'s invoices.',
    'credit_not_enough' => 'This customer only has :available of credit available.',
    'credit_payment_not_editable' => 'A payment made from customer credit cannot be edited into a cash payment. Remove it and record the payment again.',
    'credit_already_used' => ':used of this customer credit has already been used to pay invoices, so it cannot be removed or reduced below that. Remove those invoice payments first.',

    // Audit fixes, round 3 (M1, M2, M5, M8).
    'supplier_payment_needs_bill' => 'To pay a supplier, choose the bill you are paying. A payment with no bill behind it would make the supplier\'s statement disagree with your books. For a purchase paid on the spot, record it as a Cash Expense instead.',
    'invalid_report_date' => 'One of the report dates was not a valid date, so it was ignored and the usual period is shown instead.',
    'report_range_reversed' => 'The "From" date was after the "To" date, so the two were swapped.',
    'sale_line_needs_item' => 'Pick the item being sold on this line. A line with no item skips the stock check and records no cost, which would make this sale look like pure profit.',
    'payment_managed_elsewhere' => [
        'opening_balance' => 'This payment is your starting cash or bank balance. Change it on the Opening Balance screen, not here.',
        'custody' => 'This payment is part of an employee custody. Change it on the Custody screen, not here.',
        'owner' => 'This payment is part of an owner\'s capital or withdrawal. Change it on the Owners screen, not here.',
        'other' => 'This payment was created by another screen and cannot be changed here.',
    ],
    // Low finding 11.
    'item_unit_change_needs_confirm' => 'This item has already been bought or sold, so changing its units per pack must be confirmed. Records already entered keep their old conversion; only new ones use the new figure.',
];
