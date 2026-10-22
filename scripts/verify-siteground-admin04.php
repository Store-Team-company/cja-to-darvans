<?php
/**
 * Test the ADMIN-04 SiteGround deployment preflight against a simulated
 * previous SaaS-02 installation. No real .env, database, or site is touched.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '87c17f6b0133ab75fc481e2ab773f2320010a516';
$site = sys_get_temp_dir().'/datapilot-admin04-'.bin2hex(random_bytes(5));
$target = $site.'/www/preview.test/data-pilot-app';

function requireAdmin04(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('ADMIN-04 smoke FAIL: '.$message);
    }
}
function gitAdmin04Test(string $root, array $args): array
{
    $p = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireAdmin04(is_resource($p), 'Git execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}
function runAdmin04Test(string $root, array $args): array
{
    $p = proc_open(array_merge([PHP_BINARY, $root.'/scripts/deploy-siteground-admin04.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireAdmin04(is_resource($p), 'Updater execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), $out];
}

requireAdmin04(mkdir($target.'/vendor', 0700, true), 'temporary vendor dir');
requireAdmin04(mkdir(dirname($target).'/public_html', 0700, true), 'fake public_html');
file_put_contents($target.'/.siteground-layout', 'test');
file_put_contents($target.'/.env', "SAAS_ENABLED=true\nSAAS_REGISTRATION_ENABLED=false\nSAAS_BILLING_ENABLED=false\n");
file_put_contents($target.'/artisan', "<?php // fake\n");
file_put_contents($target.'/vendor/autoload.php', "<?php // fake\n");

$files = [
    'app/Http/Controllers/BdpCustomerManagementController.php',
    'routes/balenco-data-pilot.php',
    'resources/views/balenco-data-pilot/layout.blade.php',
    'resources/views/balenco-data-pilot/customers/index.blade.php',
    'resources/views/balenco-data-pilot/customers/show.blade.php',
    'database/migrations/2026_10_22_000005_create_bdp_customer_admin_audit.php',
];
$existing = [];
foreach ($files as $path) {
    [$rc] = gitAdmin04Test($root, ['cat-file', '-e', $base.':'.$path]);
    if ($rc !== 0) {
        continue;
    }
    [$rc, $source] = gitAdmin04Test($root, ['show', $base.':'.$path]);
    requireAdmin04($rc === 0, 'could not inspect base file');
    $dest = $target.'/'.$path;
    requireAdmin04(is_dir(dirname($dest)) || mkdir(dirname($dest), 0700, true), 'making app directory');
    file_put_contents($dest, $source);
    $existing[] = $dest;
}

[$rc, $output] = runAdmin04Test($root, ['--check', '--target='.$target]);
requireAdmin04($rc === 0 && str_contains($output, 'Preflight PASS: 6 files checked; 6 files to update'),
    'baseline compatibility: '.$output);
requireAdmin04(!is_dir(dirname($target).'/datapilot-private-backups'), 'dry-run wrote backup');
requireAdmin04(!file_exists($target.'/app/Http/Controllers/BdpCustomerManagementController.php'), 'dry-run wrote mailer');

[$rc, $output] = runAdmin04Test($root, ['--apply', '--target='.$target]);
requireAdmin04($rc !== 0 && str_contains($output, '--accept-disposable-installation'),
    'apply without accepted risk incorrectly succeeded');

requireAdmin04(count($existing) > 0, 'no existing files found');
file_put_contents($existing[0], "\n// unknown local customization\n", FILE_APPEND);
[$rc, $output] = runAdmin04Test($root, ['--check', '--target='.$target]);
requireAdmin04($rc !== 0 && str_contains($output, 'differs from approved base'),
    'preflight did not protect a customized live file');

echo "PASS: ADMIN-04 updater verified 6 paths, read-only preflight, explicit consent and unknown-file protection.\n";
