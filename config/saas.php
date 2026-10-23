<?php

return [
    // Existing single-store deployments stay unchanged unless explicitly enabled.
    'enabled' => (bool) env('SAAS_ENABLED', false),
    'registration_enabled' => (bool) env('SAAS_REGISTRATION_ENABLED', false),
    'billing_enabled' => (bool) env('SAAS_BILLING_ENABLED', false),
    'admin_grants_enabled' => (bool) env('SAAS_ADMIN_GRANTS_ENABLED', false),
    'public_name' => env('SAAS_PUBLIC_NAME', 'Data Pilot'),

    // Product retrieval is separate from CJ authentication and Botble pairing.
    // Remains OFF even for paid users until separately authorized and tested.
    'cj_draft_import_enabled' => (bool) env('SAAS_CJ_DRAFT_IMPORT_ENABLED', false),

    // One explicitly authorized internal CJ pilot workspace only; default OFF.
    // Separate from Botble pairing and paid subscriptions.
    'cj_pilot' => [
        'enabled' => (bool) env('SAAS_CJ_PILOT_ENABLED', false),
        'workspace_id' => env('SAAS_CJ_PILOT_WORKSPACE_ID', ''),
    ],

    // Server-pinned, first-party Botble sandbox only. Default OFF.
    // Never grants CJ access, catalog publishing, or billing.
    'botble_pilot' => [
        'enabled' => (bool) env('SAAS_BOTBLE_PILOT_ENABLED', false),
        'workspace_id' => env('SAAS_BOTBLE_PILOT_WORKSPACE_ID', ''),
        'store_id' => env('SAAS_BOTBLE_PILOT_STORE_ID', ''),
        'store_url' => env('SAAS_BOTBLE_PILOT_STORE_URL', ''),
    ],

    // Stripe LIVE/TEST keys belong only in the server's private .env.
    // Price IDs must be created and configured in Stripe before checkout works.
    'stripe_secret' => env('STRIPE_SECRET_KEY', ''),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    'plans' => [
        'starter' => [
            'name' => 'Starter', 'price_label' => '$19', 'product_limit' => 250,
            'store_limit' => 1, 'seat_limit' => 2, 'sync_label' => 'Manual sync',
            'stripe_price_id' => env('STRIPE_PRICE_STARTER', ''),
        ],
        'growth' => [
            'name' => 'Growth', 'price_label' => '$49', 'product_limit' => 2000,
            'store_limit' => 2, 'seat_limit' => 5, 'sync_label' => 'Scheduled sync',
            'stripe_price_id' => env('STRIPE_PRICE_GROWTH', ''),
        ],
        'agency' => [
            'name' => 'Agency', 'price_label' => '$99', 'product_limit' => 5000,
            'store_limit' => 5, 'seat_limit' => 15, 'sync_label' => 'Priority sync',
            'stripe_price_id' => env('STRIPE_PRICE_AGENCY', ''),
        ],
    ],
];
