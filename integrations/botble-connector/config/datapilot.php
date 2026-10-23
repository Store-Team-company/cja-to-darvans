<?php

return [
    // Configure the trusted SaaS URL in the Botble server's private .env.
    // A signed merchant-controlled destination URL is never accepted from a browser form.
    'saas_url' => env('DATAPILOT_SAAS_URL', ''),
    // Inbound staging API remains OFF until independent Botble version testing.
    'inbound_enabled' => (bool) env('DATAPILOT_INBOUND_DRAFTS_ENABLED', false),
    // Protocol v2: atomically stage up to 100 variants. Off by default until
    // the sender and exact deployed connector are verified on the sandbox.
    'variant_drafts_enabled' => (bool) env('DATAPILOT_VARIANT_DRAFTS_ENABLED', false),
    // Write access requires a separate operator-controlled staging switch.
    'catalog_import_enabled' => (bool) env('DATAPILOT_CATALOG_IMPORT_ENABLED', false),
];
