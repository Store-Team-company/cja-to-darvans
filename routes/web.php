<?php

// Application routes, including the authenticated dashboard at /.
require __DIR__ . '/balenco-data-pilot.php';

// Opt-in customer SaaS routes; disabled by default on existing deployments.
require __DIR__ . '/saas.php';
