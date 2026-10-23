<?php

/** CJ-01 staging delta safety contract — never talks to the real server. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'ac8dd7874409d39de68c34214e18c44416b1ae1a';
$temp = sys_get_temp_dir().'/dp-cj01-'.bin2hex(random_bytes(6));
$target = $temp.'/www/test.example/data-pilot-app';

function cj01Assert(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException('CJ-01 staging smoke FAIL: '.$message);
    }
}

function cj01Exec(array $args, string $cwd): array
{
    $proc = proc_open($args, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    cj01Assert(is_resource($proc), 'Cannot start process');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out];
}

function cj01Write(string $path, string $contents): void
{
    cj01Assert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'mkdir');
    cj01Assert(file_put_contents($path, $contents) !== false, 'write');
}

cj01Assert(mkdir($target.'/vendor', 0700, true), 'test vendor');
cj01Assert(mkdir(dirname($target).'/public_html', 0700, true), 'test public_html');
foreach (['.env', '.siteground-layout', 'artisan', 'vendor/autoload.php'] as $path) {
    cj01Write($target.'/'.$path, 'test fixture');
}
$existing = [
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Services/Saas/SaasFirstPartyPilot.php',
    'database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php',
    'app/Http/Controllers/Saas/SaasConnectionController.php',
    'app/Http/Middleware/EnsureSaasEntitlement.php',
    'resources/views/saas/connections.blade.php',
    'routes/api.php',
    'routes/saas.php',
    'config/saas.php',
];
foreach ($existing as $path) {
    [$status, $contents] = cj01Exec(['git', 'show', $base.':'.$path], $root);
    cj01Assert($status === 0, 'baseline file missing: '.$path);
    cj01Write($target.'/'.$path, $contents);
}

$installer = $root.'/scripts/deploy-siteground-cj01.php';
[$status, $output] = cj01Exec([PHP_BINARY, $installer, '--check', '--target='.$target], $root);
cj01Assert($status === 0 && str_contains($output, 'PASS: read-only CJ-01 preflight'),
    'dry-run failed: '.$output);
cj01Assert(str_contains($output, 'replace routes/saas.php')
    && str_contains($output, 'add app/Services/Saas/SaasCjApiClient.php')
    && str_contains($output, 'add database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php'),
    'manifest incomplete');
cj01Assert(! is_dir(dirname($target).'/datapilot-private-backups'),
    'dry run touched private backup');

[$status, $output] = cj01Exec([
    PHP_BINARY, $installer, '--apply', '--backup-confirmed', '--target='.$target,
], $root);
cj01Assert($status !== 0 && str_contains($output, '--expected-head'),
    'apply without expected SHA succeeded');

cj01Write($target.'/routes/api.php',
    (string) file_get_contents($target.'/routes/api.php')."\n// user customization to preserve\n");
[$status, $output] = cj01Exec([PHP_BINARY, $installer, '--check', '--target='.$target], $root);
cj01Assert($status !== 0 && str_contains($output, 'SiteGround customization'),
    'unknown live API route customization silently overwritten');

echo "PASS: CJ-01 selective installer enforces exact source, SHA, dry-run and preserves unexpected customizations.\n";
