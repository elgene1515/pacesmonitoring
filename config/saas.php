<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial period
    |--------------------------------------------------------------------------
    |
    | Every newly registered organization starts on a trial of the default plan
    | for this many days. The owner then assigns a paid plan from the owner
    | dashboard (billing itself is handled outside the app: annual invoices).
    |
    */

    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 30),

    /*
    | Slug of the plan a new organization's trial runs on.
    */

    'default_plan' => env('SAAS_DEFAULT_PLAN', 'starter'),

    /*
    | Subscriptions ending within this many days are listed as "expiring soon"
    | on the owner dashboard and warned about on the organization's pages.
    */

    'expiring_soon_days' => (int) env('SAAS_EXPIRING_SOON_DAYS', 30),

    /*
    | Shown to schools whose subscription has lapsed, so they know who to contact.
    */

    'support_email' => env('SAAS_SUPPORT_EMAIL', 'support@example.com'),

];
