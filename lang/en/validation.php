<?php

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — Validation Strings (English)
//
//  Deliberately thin: Laravel's own English messages are already
//  good, and restating them here would only create two copies to
//  keep in step. What this file exists for is the `attributes`
//  block below.
//
//  Without it, Laravel names a field by its database column — "The
//  company_name field is required", "The holder_id field is
//  required" — which reads like a developer error rather than an
//  instruction. Everything absent from this file falls through to
//  the framework's own wording, which is what we want.
//
//  Keep this list in step with lang/ar/validation.php's attributes.
//  ValidationLanguageTest checks that they match.
// ══════════════════════════════════════════════════════════════════

return [

    // Laravel has no built-in message for "at least one capital
    // letter" (see App\Rules\ContainsUppercaseLetter), so this one
    // line is ours. The other password messages (letters, numbers,
    // symbols) still come from the framework.
    'password' => [
        'uppercase' => 'The password must contain at least one capital letter (e.g. A).',
    ],

    // Inventory purchase quantities — see App\Rules\HalfStepQuantity.
    'half_step_qty' => 'Quantity must be a whole or half number (e.g. 1, 1.5, 2, 2.5).',

    // Sales date — see StoreSaleRequest::messages().
    'sale_date_not_future' => 'A sale can be recorded for today or any past date. Future dates are not allowed.',

    'attributes' => [
        'name'                  => 'name',
        'company_name'          => 'company name',
        'email'                 => 'email',
        'password'              => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password'      => 'current password',
        'currency'              => 'currency',
        'language'              => 'language',
        'code'                  => 'verification code',
        'date'                  => 'date',
        'due_date'              => 'due date',
        'given_at'              => 'date handed out',
        'settlement_date'       => 'settlement date',
        'amount'                => 'amount',
        'amount_now'            => 'amount paid now',
        'qty'                   => 'quantity',
        'qty_produced'          => 'quantity produced',
        'unit_price'            => 'unit price',
        'labor_cost'            => 'labour cost',
        'customer_id'           => 'customer',
        'vendor_id'             => 'supplier',
        'category_id'           => 'category',
        'item_id'               => 'item',
        'holder_id'             => 'custody holder',
        'method'                => 'payment method',
        'vat_rate'              => 'VAT rate',
        'business_types'        => 'business type',
    ],

];
