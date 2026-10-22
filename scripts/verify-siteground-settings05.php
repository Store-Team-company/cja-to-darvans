<?php
/**
 * Test the SETTINGS-05 SiteGround deployment preflight against a simulated
 * previous SaaS-02 installation. No real .env, database, or site is touched.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '52938ab67de5723e7584d894c7c16bb9ba6012fe';
$site = sys_get_temp_dir().'/datapilot-settings05-'.bin2hex(random_bytes(5));
$target = $site.'/www/preview.test/data-pilot-app';

function requireSettings05(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('SETTINGS-05 smoke FAIL: '.$message);
    }
}
function gitSettings05Test(string $root, array $args): array
{
    $p = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireSettings05(is_resource($p), 'Git execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}
function runSettings05Test(string $root, array $args): array
{
    $p = proc_open(array_merge([PHP_BINARY, $root.'/scripts/deploy-siteground-settings05.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireSettings05(is_resource($p), 'Updater execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), $out];
}

requireSettings05(mkdir($target.'/vendor', 0700, true), 'temporary vendor dir');
requireSettings05(mkdir(dirname($target).'/public_html', 0700, true), 'fake public_html');
file_put_contents($target.'/.siteground-layout', 'test');
file_put_contents($target.'/.env', "SAAS_ENABLED=true\nSAAS_REGISTRATION_ENABLED=false\nSAAS_BILLING_ENABLED=false\n");
file_put_contents($target.'/artisan', "<?php // fake\n");
file_put_contents($target.'/vendor/autoload.php', "<?php // fake\n");

$files = [
    'app/Http/Controllers/BdpSettingsController.php',
    'resources/views/balenco-data-pilot/settings.blade.php',
];
$existing = [];
foreach ($files as $path) {
    [$rc] = gitSettings05Test($root, ['cat-file', '-e', $base.':'.$path]);
    if ($rc !== 0) {
        continue;
    }
    [$rc, $source] = gitSettings05Test($root, ['show', $base.':'.$path]);
    requireSettings05($rc === 0, 'could not inspect base file');
    $dest = $target.'/'.$path;
    requireSettings05(is_dir(dirname($dest)) || mkdir(dirname($dest), 0700, true), 'making app directory');
    file_put_contents($dest, $source);
    $existing[] = $dest;
}

[$rc, $output] = runSettings05Test($root, ['--check', '--target='.$target]);
requireSettings05($rc === 0 && str_contains($output, 'Preflight PASS: 2 files checked; 2 files to update'),
    'baseline compatibility: '.$output);
requireSettings05(!is_dir(dirname($target).'/datapilot-private-backups'), 'dry-run wrote backup');
// Both patch targets exist in the approved previous release; a read-only
// preflight must retain the old source exactly, not delete or replace it.
[$oldCode, $oldContents] = gitSettings05Test($root, ['show', $base.':app/Http/Controllers/BdpSettingsController.php']);
requireSettings05($oldCode === 0
    && hash('sha256', (string) file_get_contents($target.'/app/Http/Controllers/BdpSettingsController.php'))
        === hash('sha256', $oldContents), 'dry-run modified controller code');

[$rc, $output] = runSettings05Test($root, ['--apply', '--target='.$target]);
requireSettings05($rc !== 0 && str_contains($output, '--accept-disposable-installation'),
    'apply without accepted risk incorrectly succeeded');

requireSettings05(count($existing) > 0, 'no existing files found');
file_put_contents($existing[0], "\n// unknown local customization\n", FILE_APPEND);
[$rc, $output] = runSettings05Test($root, ['--check', '--target='.$target]);
requireSettings05($rc !== 0 && str_contains($output, 'differs from approved base'),
    'preflight did not protect a customized live file');

echo "PASS: SETTINGS-05 updater verified 2 paths, read-only preflight, explicit consent and unknown-file protection.\n";
