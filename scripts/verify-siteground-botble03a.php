<?php
/**
 * Test the BOTBLE-03A SiteGround deployment preflight against a simulated
 * previous SaaS-02 installation. No real .env, database, or site is touched.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'd2805c2839c1f04ddb5a5cbf97ccbf335a2aa853';
$site = sys_get_temp_dir().'/datapilot-botble03a-'.bin2hex(random_bytes(5));
$target = $site.'/www/preview.test/data-pilot-app';

function requireBotble03A(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('BOTBLE-03A smoke FAIL: '.$message);
    }
}
function gitBotble03ATest(string $root, array $args): array
{
    $p = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireBotble03A(is_resource($p), 'Git execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}
function runBotble03ATest(string $root, array $args): array
{
    $p = proc_open(array_merge([PHP_BINARY, $root.'/scripts/deploy-siteground-botble03a.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireBotble03A(is_resource($p), 'Updater execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), $out];
}

requireBotble03A(mkdir($target.'/vendor', 0700, true), 'temporary vendor dir');
requireBotble03A(mkdir(dirname($target).'/public_html', 0700, true), 'fake public_html');
file_put_contents($target.'/.siteground-layout', 'test');
file_put_contents($target.'/.env', "SAAS_ENABLED=true\nSAAS_REGISTRATION_ENABLED=false\nSAAS_BILLING_ENABLED=false\n");
file_put_contents($target.'/artisan', "<?php // fake\n");
file_put_contents($target.'/vendor/autoload.php', "<?php // fake\n");

$files = [
    'app/Http/Controllers/Saas/SaasBotblePairingController.php',
    'routes/saas.php',
    'routes/api.php',
    'resources/views/saas/connections.blade.php',
    'database/migrations/2026_10_22_000004_create_saas_botble_pairing_challenges.php',
];
$existing = [];
foreach ($files as $path) {
    [$rc] = gitBotble03ATest($root, ['cat-file', '-e', $base.':'.$path]);
    if ($rc !== 0) {
        continue;
    }
    [$rc, $source] = gitBotble03ATest($root, ['show', $base.':'.$path]);
    requireBotble03A($rc === 0, 'could not inspect base file');
    $dest = $target.'/'.$path;
    requireBotble03A(is_dir(dirname($dest)) || mkdir(dirname($dest), 0700, true), 'making app directory');
    file_put_contents($dest, $source);
    $existing[] = $dest;
}

[$rc, $output] = runBotble03ATest($root, ['--check', '--target='.$target]);
requireBotble03A($rc === 0 && str_contains($output, 'Preflight PASS: 5 files checked; 5 files to update'),
    'baseline compatibility: '.$output);
requireBotble03A(!is_dir(dirname($target).'/datapilot-private-backups'), 'dry-run wrote backup');
requireBotble03A(!file_exists($target.'/app/Http/Controllers/Saas/SaasBotblePairingController.php'), 'dry-run wrote mailer');

[$rc, $output] = runBotble03ATest($root, ['--apply', '--target='.$target]);
requireBotble03A($rc !== 0 && str_contains($output, '--accept-disposable-installation'),
    'apply without accepted risk incorrectly succeeded');

requireBotble03A(count($existing) > 0, 'no existing files found');
file_put_contents($existing[0], "\n// unknown local customization\n", FILE_APPEND);
[$rc, $output] = runBotble03ATest($root, ['--check', '--target='.$target]);
requireBotble03A($rc !== 0 && str_contains($output, 'differs from approved base'),
    'preflight did not protect a customized live file');

echo "PASS: BOTBLE-03A updater verified 5 paths, read-only preflight, explicit consent and unknown-file protection.\n";
