<?php
/**
 * SaaS-02 SiteGround deployer dry-run smoke test.
 * Runs only against temporary simulated application, never real credentials.
 */
declare(strict_types=1);

$repo = dirname(__DIR__);
$base = 'b64811521d43b4575f840974b1fc08306f9542a9';
$directory = sys_get_temp_dir().'/saas-02-qa-'.bin2hex(random_bytes(5));
$target = $directory.'/www/preview.test/data-pilot-app';

function must(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('SaaS-02 deployer smoke FAIL: '.$description);
    }
}
function gitValue(string $repo, array $args): array
{
    $process = proc_open(array_merge(['git'], $args),
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $repo);
    must(is_resource($process), 'cannot start git');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $out, (string) $err];
}
function runStage(string $repo, array $args): array
{
    $process = proc_open(array_merge([PHP_BINARY, $repo.'/scripts/deploy-siteground-saas-02.php'], $args),
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $repo);
    must(is_resource($process), 'cannot start deployer');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
}

must(mkdir($target.'/vendor', 0700, true), 'cannot make fake vendor');
must(mkdir(dirname($target).'/public_html', 0700, true), 'cannot make public html');
file_put_contents($target.'/.siteground-layout', 'test');
file_put_contents($target.'/.env', "APP_ENV=production\nSAAS_REGISTRATION_ENABLED=false\nSAAS_BILLING_ENABLED=false\n");
file_put_contents($target.'/artisan', "<?php // qa\n");
file_put_contents($target.'/vendor/autoload.php', "<?php // qa\n");

[$rc, $names] = gitValue($repo, ['diff', '--name-only', $base, 'HEAD', '--',
    'app/Http/Controllers/Saas', 'app/Http/Middleware/EnsureSaasEntitlement.php',
    'app/Models/SaasWorkspace.php', 'app/Models/SaasWorkspaceBilling.php',
    'app/Services/Saas', 'config/saas.php',
    'database/migrations/2026_10_22_000002_create_saas_billing_and_webhook_events.php',
    'resources/views/saas', 'routes/api.php', 'routes/saas.php',
]);
must($rc === 0, 'git diff failed');
$paths = array_values(array_filter(explode("\n", trim($names))));
must(count($paths) === 32, 'expected exactly 32 changed application paths, got '.count($paths));

$oneExisting = null;
foreach ($paths as $path) {
    [$exists] = gitValue($repo, ['cat-file', '-e', $base.':'.$path]);
    if ($exists !== 0) {
        continue;
    }
    [$rc, $contents] = gitValue($repo, ['show', $base.':'.$path]);
    must($rc === 0, 'cannot read baseline '.$path);
    $destination = $target.'/'.$path;
    must(is_dir(dirname($destination)) || mkdir(dirname($destination), 0700, true),
        'cannot make target folder');
    file_put_contents($destination, $contents);
    $oneExisting ??= $destination;
}

[$status, $output] = runStage($repo, ['--check', '--target='.$target]);
must($status === 0 && str_contains($output, 'Preflight PASS: 32 scoped files'),
    'valid SiteGround install preflight failed: '.$output);
must(!is_dir(dirname($target).'/datapilot-private-backups'), 'dry-run made backups');
must(!is_file($target.'/app/Services/Saas/SaasEntitlements.php'),
    'dry-run copied SaaS code');

[$status, $output] = runStage($repo, ['--apply', '--target='.$target]);
must($status !== 0 && str_contains($output, '--backup-confirmed'),
    'applied without backup confirmation');

must($oneExisting !== null, 'no existing baseline to tamper');
file_put_contents($oneExisting, "\n// owner customization\n", FILE_APPEND);
[$status, $output] = runStage($repo, ['--check', '--target='.$target]);
must($status !== 0 && str_contains($output, 'does not match the approved base'),
    'customized code was not rejected');

echo "PASS: SaaS-02 dry-run validated 32 paths, preserved existing files, required backup and refused local changes.\n";
