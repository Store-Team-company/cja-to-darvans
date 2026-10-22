<?php

/** AUTH-05 offline deployment preflight safety smoke test. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '1dba1784083c8e4a9bb6c765c3e7ff12fd8d38d7';
$temporary = sys_get_temp_dir().'/datapilot-auth05-'.bin2hex(random_bytes(5));
$target = $temporary.'/www/staging.test/data-pilot-app';

function auth05Assert(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException('AUTH-05 smoke FAIL: '.$reason);
    }
}

function auth05Exec(array $command, string $dir): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $dir);
    auth05Assert(is_resource($process), 'Cannot start process');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

function auth05Put(string $file, string $body): void
{
    auth05Assert(is_dir(dirname($file)) || mkdir(dirname($file), 0700, true), 'Cannot create parent');
    auth05Assert(file_put_contents($file, $body) !== false, 'Cannot write mock');
}

auth05Assert(mkdir($target.'/vendor', 0700, true), 'Cannot make mock app');
auth05Assert(mkdir(dirname($target).'/public_html', 0700, true), 'Cannot make mock web root');
foreach (['.siteground-layout', '.env', 'artisan', 'vendor/autoload.php'] as $path) {
    auth05Put($target.'/'.$path, 'safe-test');
}
foreach ([
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'app/Models/User.php',
    'routes/saas.php',
    'routes/balenco-data-pilot.php',
    'resources/views/saas/auth/login.blade.php',
    'resources/views/saas/auth/register.blade.php',
    'resources/views/balenco-data-pilot/layout.blade.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'database/migrations/2026_10_22_000003_create_saas_email_otp_challenges_table.php',
] as $path) {
    [$status, $source] = auth05Exec(['git', 'show', $base.':'.$path], $root);
    auth05Assert($status === 0, 'Missing baseline: '.$path);
    auth05Put($target.'/'.$path, $source);
}
$deployer = $root.'/scripts/deploy-siteground-auth05.php';
[$status, $result] = auth05Exec(
    [PHP_BINARY, $deployer, '--check', '--target='.$target],
    $root
);
auth05Assert($status === 0 && str_contains($result, 'PASS: read-only preflight'),
    'Dry-run failed: '.$result);
auth05Assert(str_contains($result, 'replace routes/saas.php')
    && str_contains($result, 'add app/Http/Controllers/Saas/SaasCustomerAdminController.php')
    && str_contains($result, 'add database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php'),
    'Missing change action');
auth05Assert(! is_dir(dirname($target).'/datapilot-private-backups'),
    'Dry run created backup');

// Real SiteGround reported a customized legacy route file. Ensure the
// updater preserves unrelated local code and patches only exact admin links.
$routeFile = $target.'/routes/balenco-data-pilot.php';
$layoutFile = $target.'/resources/views/balenco-data-pilot/layout.blade.php';
auth05Put($routeFile, (string) file_get_contents($routeFile)
    ."\n// EXAMPLE SITEGROUND-ONLY ROUTE CUSTOMIZATION; MUST SURVIVE\n");
auth05Put($layoutFile, (string) file_get_contents($layoutFile)
    ."\n{{-- EXAMPLE SITEGROUND-ONLY LAYOUT CUSTOMIZATION; MUST SURVIVE --}}\n");
$routeHash = hash_file('sha256', $routeFile);
$layoutHash = hash_file('sha256', $layoutFile);
[$status, $result] = auth05Exec([PHP_BINARY, $deployer,
    '--check', '--target='.$target], $root);
auth05Assert($status === 0
    && str_contains($result, 'merge routes/balenco-data-pilot.php')
    && str_contains($result, 'merge resources/views/balenco-data-pilot/layout.blade.php'),
    'SiteGround-customized legacy file safe-merge dry run failed: '.$result);
auth05Assert($routeHash === hash_file('sha256', $routeFile)
    && $layoutHash === hash_file('sha256', $layoutFile),
    'Customized files changed during --check');

[$status, $result] = auth05Exec(
    [PHP_BINARY, $deployer, '--apply', '--backup-confirmed', '--target='.$target],
    $root
);
auth05Assert($status !== 0 && str_contains($result, '--expected-head'),
    'Apply without exact SHA was allowed');

auth05Put($target.'/routes/saas.php',
    (string) file_get_contents($target.'/routes/saas.php')."\n// unexpected customization\n");
[$status, $result] = auth05Exec(
    [PHP_BINARY, $deployer, '--check', '--target='.$target],
    $root
);
auth05Assert($status !== 0 && str_contains($result, 'Live file customized'),
    'Unknown live edit was not protected');

// Restore unmodified SaaS routes; remove the verified insertion anchor from
// the customized legacy route. The updater must ABORT instead of guessing.
[$status, $originalSaas] = auth05Exec(['git', 'show', $base.':routes/saas.php'], $root);
auth05Assert($status === 0, 'Could not restore SaaS route fixture');
auth05Put($target.'/routes/saas.php', $originalSaas);
$legacy = (string) file_get_contents($routeFile);
auth05Assert(str_contains($legacy, "    Route::get('/profile', [BdpProfileController::class, 'index'])->name('profile');"),
    'Missing test route anchor');
auth05Put($routeFile, str_replace(
    "    Route::get('/profile', [BdpProfileController::class, 'index'])->name('profile');",
    "    // profile has been independently refactored",
    $legacy
));
[$status, $result] = auth05Exec([PHP_BINARY, $deployer, '--check', '--target='.$target], $root);
auth05Assert($status !== 0 && str_contains($result, 'Missing/ambiguous legacy admin route'),
    'Ambiguous customized admin route must abort');

echo "PASS: AUTH-05 deployment restricts source and expected head, protects customizations and performs read-only checks.\n";
