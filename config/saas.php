<?php

return [
    // Deliberately off on all existing single-store deployments.
    // Enabling this does NOT enable paid imports; billing gates come later.
    'enabled' => (bool) env('SAAS_ENABLED', false),
    // Do not publicly accept accounts before Stripe and isolation QA.
    'registration_enabled' => (bool) env('SAAS_REGISTRATION_ENABLED', false),
    'public_name' => env('SAAS_PUBLIC_NAME', 'Data Pilot'),
];
