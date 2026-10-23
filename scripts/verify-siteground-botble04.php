<?php

/** BOTBLE-04 staging delta safety contract — never talks to the real server. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '901424ebf486dd1291b81c28fd1c66e934b6cbf7';
$temp = sys_get_temp_dir().'/dp-botble04-'.bin2hex(random_bytes(6));
$target = $temp.'/www/test.example/data-pilot-app';

function botble04Assert(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException('BOTBLE-04 staging smoke FAIL: '.$message);
    }
}

function botble04Exec(array $args, string $cwd): array
{
    $proc = proc_open($args, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    botble04Assert(is_resource($proc), 'Cannot start process');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out];
}

function botble04Write(string $path, string $contents): void
{
    botble04Assert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'mkdir');
    botble04Assert(file_put_contents($path, $contents) !== false, 'write');
}

botble04Assert(mkdir($target.'/vendor', 0700, true), 'test vendor');
botble04Assert(mkdir(dirname($target).'/public_html', 0700, true), 'test public_html');
foreach (['.env', '.siteground-layout', 'artisan', 'vendor/autoload.php'] as $path) {
    botble04Write($target.'/'.$path, 'test fixture');
}
$existing = [
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Http/Controllers/Saas/SaasConnectionController.php',
    'app/Http/Middleware/EnsureSaasEntitlement.php',
    'resources/views/saas/connections.blade.php',
    'routes/api.php',
    'routes/saas.php',
    'config/saas.php',
];
foreach ($existing as $path) {
    [$status, $contents] = botble04Exec(['git', 'show', $base.':'.$path], $root);
    botble04Assert($status === 0, 'baseline file missing: '.$path);
    botble04Write($target.'/'.$path, $contents);
}

$installer = $root.'/scripts/deploy-siteground-botble04.php';
[$status, $output] = botble04Exec([PHP_BINARY, $installer, '--check', '--target='.$target], $root);
botble04Assert($status === 0 && str_contains($output, 'PASS: read-only BOTBLE-04 preflight'),
    'dry-run failed: '.$output);
botble04Assert(str_contains($output, 'replace routes/saas.php')
    && str_contains($output, 'add app/Http/Controllers/Saas/SaasBotblePairingController.php')
    && str_contains($output, 'add database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php'),
    'manifest incomplete');
botble04Assert(! is_dir(dirname($target).'/datapilot-private-backups'),
    'dry run touched private backup');

[$status, $output] = botble04Exec([
    PHP_BINARY, $installer, '--apply', '--backup-confirmed', '--target='.$target,
], $root);
botble04Assert($status !== 0 && str_contains($output, '--expected-head'),
    'apply without expected SHA succeeded');

botble04Write($target.'/routes/api.php',
    (string) file_get_contents($target.'/routes/api.php')."\n// user customization to preserve\n");
[$status, $output] = botble04Exec([PHP_BINARY, $installer, '--check', '--target='.$target], $root);
botble04Assert($status !== 0 && str_contains($output, 'SiteGround customization'),
    'unknown live API route customization silently overwritten');

echo "PASS: BOTBLE-04 selective installer enforces exact source, SHA, dry-run and preserves unexpected customizations.\n";
