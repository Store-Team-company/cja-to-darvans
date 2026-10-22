<?php

return [
    // Existing single-store deployments stay unchanged unless explicitly enabled.
    'enabled' => (bool) env('SAAS_ENABLED', false),
    'registration_enabled' => (bool) env('SAAS_REGISTRATION_ENABLED', false),
    'billing_enabled' => (bool) env('SAAS_BILLING_ENABLED', false),
    'public_name' => env('SAAS_PUBLIC_NAME', 'Data Pilot'),

    // One fixed, first-party STAGING Botble store only. Must be explicitly
    // enabled on the Data Pilot server, never via an admin browser setting.
    // This does not activate billing or give a customer a free SaaS plan.
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
