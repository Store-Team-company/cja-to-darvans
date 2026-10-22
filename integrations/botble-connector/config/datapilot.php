<?php

return [
    // Configure the trusted SaaS URL in the Botble server's private .env.
    // A signed merchant-controlled destination URL is never accepted from a browser form.
    'saas_url' => env('DATAPILOT_SAAS_URL', ''),
    // Inbound staging API remains OFF until independent Botble version testing.
    'inbound_enabled' => (bool) env('DATAPILOT_INBOUND_DRAFTS_ENABLED', false),
];
