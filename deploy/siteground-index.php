<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// In the SiteGround distribution this file is placed in public_html,
// beside (not inside) the private data-pilot-app directory.
$appRoot = realpath(__DIR__.'/../data-pilot-app');
if ($appRoot === false || !is_file($appRoot.'/bootstrap/app.php')) {
    http_response_code(503);
    exit('Data Pilot application files are missing. Verify the SiteGround ZIP extraction layout.');
}

// Never dispatch public dashboard/CJA endpoints until migrations succeed
// and the one-use browser installer has been permanently locked.
if (!is_file($appRoot.'/storage/app/.siteground-installed')) {
    header('Location: /setup.php', true, 302);
    exit;
}

if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appRoot.'/vendor/autoload.php';

$app = require_once $appRoot.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

$response = $kernel->handle($request = Request::capture())->send();
$kernel->terminate($request, $response);
