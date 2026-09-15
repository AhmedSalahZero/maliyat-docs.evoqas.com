<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Free trial length
    |--------------------------------------------------------------------------
    |
    | How long a newly registered company may use the app before its
    | access stops. Applied by App\Services\RegisterService and by
    | Admin\CompanyController when an admin onboards a company by hand.
    |
    */
    'trial_months' => (int) env('SUBSCRIPTION_TRIAL_MONTHS', 2),

    /*
    |--------------------------------------------------------------------------
    | Advance warning
    |--------------------------------------------------------------------------
    |
    | How many days before the trial ends the company admin is emailed,
    | and how many days out the in-app banner starts showing. Both
    | default to a week so the customer is told well before they lose
    | access rather than on the morning it happens.
    |
    */
    'notify_days_before' => (int) env('SUBSCRIPTION_NOTIFY_DAYS_BEFORE', 7),

    /*
    |--------------------------------------------------------------------------
    | Re-notification interval
    |--------------------------------------------------------------------------
    |
    | The reminder command runs daily. Without this, a company in its
    | final week would be emailed every single morning — this is the
    | minimum number of days between two reminders to the same company.
    |
    */
    'notify_again_after_days' => (int) env('SUBSCRIPTION_NOTIFY_AGAIN_AFTER_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Who to contact to renew
    |--------------------------------------------------------------------------
    |
    | Renewal is handled by a person, not a payment page — there is
    | deliberately no self-service billing flow in the app. So the
    | countdown banner has to say WHO to talk to, or it is telling
    | the customer their access is ending and leaving them with
    | nowhere to go.
    |
    | Both are optional. Whichever is set appears as a button on the
    | banner; with neither set the banner is a plain warning, exactly
    | as it was before.
    |
    */
    'support_email' => env('SUPPORT_EMAIL'),
    'support_phone' => env('SUPPORT_WHATSAPP'),

];
