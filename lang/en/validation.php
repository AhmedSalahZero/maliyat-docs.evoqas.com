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
