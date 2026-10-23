<?php

/**
 * Read-only, secret-safe SiteGround P0 diagnostic.
 * Output deliberately omits exception messages, request bodies and credentials.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "DATAPILOT CJ-01 READ-ONLY DIAGNOSIS\n";
echo 'Laravel: '.\Illuminate\Foundation\Application::VERSION."\n";
echo 'SaaS enabled: '.(config('saas.enabled') ? 'YES' : 'NO')."\n";
echo 'CJ pilot configured: '.(config('saas.cj_pilot.enabled') ? 'YES' : 'NO')."\n";
echo 'CJ pilot workspace pinned: '.(config('saas.cj_pilot.workspace_id') ? 'YES' : 'NO')."\n";
echo 'Botble pilot code exists: '.(class_exists(\App\Services\Saas\SaasFirstPartyPilot::class) ? 'YES' : 'NO')."\n";
echo 'CJ service code exists: '.(class_exists(\App\Services\Saas\SaasCjApiClient::class) ? 'YES' : 'NO')."\n";
echo 'Config cached: '.(is_file($root.'/bootstrap/cache/config.php') ? 'YES' : 'NO')."\n";

try {
    foreach (['saas_workspaces', 'saas_workspace_memberships', 'saas_cj_connections',
              'saas_botble_stores', 'saas_botble_pairing_challenges'] as $table) {
        echo $table.': '.(\Illuminate\Support\Facades\Schema::hasTable($table) ? 'PRESENT' : 'MISSING')."\n";
    }
    echo 'CJ expiration columns: '.(
        \Illuminate\Support\Facades\Schema::hasColumn('saas_cj_connections', 'access_token_expires_at')
            ? 'PRESENT' : 'MISSING'
    )."\n";
} catch (\Throwable $e) {
    echo 'Schema check failed (class only): '.get_class($e)."\n";
}
$files = glob($root.'/storage/logs/laravel*.log') ?: [];
usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$found = 0;
foreach (array_slice($files, 0, 3) as $file) {
    if (!is_readable($file)) {
        continue;
    }
    $fh = fopen($file, 'rb');
    if (!$fh) {
        continue;
    }
    $size = filesize($file);
    fseek($fh, max(0, $size - 262144));
    $tail = stream_get_contents($fh);
    fclose($fh);
    if (!is_string($tail)) {
        continue;
    }
    if (!preg_match_all('/^\[([^\]]+)\] (?:production|local)\.ERROR: ([^\n]*)/m', $tail, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach (array_slice(array_reverse($m), 0, 5) as $row) {
        $kind = 'UNKNOWN';
        if (preg_match('/"exception":"\[object\] \(([^(:]+)(?:\(|:)/', $row[2], $match)) {
            $kind = preg_replace('/[^A-Za-z0-9_\\\\]/', '', $match[1]);
        }
        echo 'Recent exception date: '.$row[1].' | class: '.$kind."\n";
        $found++;
    }
    break;
}
if (!$found) {
    echo "No matching recent Laravel ERROR log entries found. Check SiteGround PHP error log after reproducing HTTP 500.\n";
}
echo "This diagnostic never sends CJ requests, changes DB state, or prints tokens, keys, email addresses or exception messages.\n";
