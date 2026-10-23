<?php

/** PRODUCT-01 changes-only SiteGround safety checks on an isolated fixture. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '01279fc9a0876dedde1587981dd13677d4e93e2e';
$tmpRoot = sys_get_temp_dir().'/dp-product01-'.bin2hex(random_bytes(6));
$target = $tmpRoot.'/www/staging.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-product01.php';

function product01Assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('PRODUCT-01 staging smoke FAIL: '.$message);
    }
}
function product01Exec(array $args, string $cwd): array
{
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    product01Assert(is_resource($process), 'Unable to start test command');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}
function product01Write(string $path, string $content): void
{
    product01Assert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'mkdir fixture');
    product01Assert(file_put_contents($path, $content, LOCK_EX) !== false, 'write fixture');
}
function product01Run(array $args): array
{
    global $root, $installer, $target;
    return product01Exec([PHP_BINARY, $installer, ...$args, '--target='.$target], $root);
}
function product01Reject(array $args, string $pattern): void
{
    [$code, $result] = product01Run($args);
    product01Assert($code !== 0 && str_contains($result, $pattern), 'expected abort: '.$pattern.' '.$result);
}

product01Assert(mkdir($target.'/vendor', 0700, true), 'vendor fixture');
product01Assert(mkdir(dirname($target).'/public_html', 0700, true), 'docroot fixture');
foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $path) {
    product01Write($target.'/'.$path, 'private-test-fixture');
}
product01Write($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);

$replaced = [
    'app/Http/Controllers/Saas/SaasPortalController.php',
    'app/Models/SaasCjConnection.php',
    'config/saas.php',
    'routes/saas.php',
    'resources/views/saas/products.blade.php',
];
$prerequisites = [
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Services/Saas/SaasFirstPartyPilot.php',
    'app/Services/Saas/SaasCjApiClient.php',
    'app/Services/Saas/SaasCjPilot.php',
    'database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php',
    'database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php',
    'database/migrations/2026_10_23_000007_guard_saas_cj_credential_revisions.php',
];
foreach (array_merge($replaced, $prerequisites, ['routes/api.php']) as $file) {
    [$status, $contents] = product01Exec(['git', 'show', $base.':'.$file], $root);
    product01Assert($status === 0, 'Baseline missing '.$file);
    product01Write($target.'/'.$file, $contents);
}
product01Write($target.'/private-db-sentinel.txt', 'untouched');
$originalHashes = [];
foreach (array_merge($replaced, $prerequisites, ['routes/api.php', '.env', 'artisan', 'private-db-sentinel.txt']) as $file) {
    $originalHashes[$file] = hash_file('sha256', $target.'/'.$file);
}

[$status, $output] = product01Run(['--check']);
product01Assert($status === 0 && str_contains($output, 'PASS: read-only PRODUCT-01 preflight'),
    'Preflight failed: '.$output);
product01Assert(str_contains($output, 'replace routes/saas.php')
    && str_contains($output, 'add app/Services/Saas/SaasCjProductDraftImporter.php')
    && str_contains($output, 'add database/migrations/2026_10_23_000008_add_saas_cj_product_fetch_throttle.php'),
    'Manifest incorrect');
foreach ($originalHashes as $file => $hash) {
    product01Assert(hash_file('sha256', $target.'/'.$file) === $hash, 'Read-only check modified '.$file);
}
product01Assert(!is_dir(dirname($target).'/datapilot-private-backups'), '--check unexpectedly backed up');
echo "PASS: check is read-only for files, database sentinel, and environment.\n";

[$status, $sha] = product01Exec(['git', 'rev-parse', 'HEAD'], $root);
product01Assert($status === 0, 'Unable to identify source HEAD');
$sha = trim($sha);
product01Reject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'Apply requires');
product01Reject(['--apply', '--expected-head='.$sha], 'Apply requires');
product01Reject(['--apply', '--backup-confirmed'], 'Apply requires');
echo "PASS: apply requires backup confirmation and exact reviewed SHA.\n";

$route = $target.'/routes/saas.php';
$original = file_get_contents($route);
product01Write($route, $original."\n// Customer-only customization; must never be overwritten.\n");
$hash = hash_file('sha256', $route);
product01Reject(['--check'], 'SiteGround customization in routes/saas.php');
product01Assert(hash_file('sha256', $route) === $hash, 'Refusal modified a customized live file');
product01Write($route, $original);

unlink($route);
product01Reject(['--check'], 'SiteGround customization in routes/saas.php');
product01Write($route, $original);
rename($route, $target.'/routes/saas-original.php');
symlink($target.'/routes/api.php', $route);
product01Reject(['--check'], 'Missing/unsafe source or symlink');
unlink($route);
rename($target.'/routes/saas-original.php', $route);
echo "PASS: missing, customized and symlinked live manifest files are protected.\n";

$needed = $target.'/'.$prerequisites[0];
$body = file_get_contents($needed);
unlink($needed);
product01Reject(['--check'], 'Prerequisite SaaS/CJ-01 file missing');
product01Write($needed, $body);

$unknown = $tmpRoot.'/invalid-installation';
mkdir($unknown, 0700, true);
[$status, $out] = product01Exec([PHP_BINARY, $installer, '--check', '--target='.$unknown], $root);
product01Assert($status !== 0 && str_contains($out, 'Unrecognized private DataPilot SiteGround installation'),
    'Unknown installation accepted');
echo "PASS: missing prerequisites and unknown installation refused.\n";

$tracked = $root.'/docs/DATAPILOT_CJ_01_QA_AND_SITEGROUND.md';
$trackedOriginal = file_get_contents($tracked);
try {
    product01Write($tracked, $trackedOriginal."\n<!-- dirty test -->\n");
    product01Reject(['--check'], 'Use a clean reviewed PRODUCT-01 checkout');
} finally {
    product01Write($tracked, $trackedOriginal);
}
product01Assert(hash_file('sha256', $tracked) === hash('sha256', $trackedOriginal), 'Dirty fixture not cleaned');
echo "PASS: dirty source tree refused.\n";

[$status, $output] = product01Run(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
product01Assert($status === 0 && str_contains($output, 'PRODUCT-01 draft retrieval installed'), 'Sandbox apply failed: '.$output);
product01Assert(str_contains($output, '2026_10_23_000008_add_saas_cj_product_fetch_throttle.php'),
    'Additive migration not dispatched');
preg_match('/Private rollback backup: ([^\n]+)/', $output, $matches);
$backup = $matches[1] ?? '';
product01Assert($backup !== '' && is_dir($backup) && !str_contains($backup, '/public_html/'),
    'Backup not private');
foreach ($replaced as $file) {
    product01Assert(is_file($backup.'/'.$file)
        && hash_file('sha256', $backup.'/'.$file) === $originalHashes[$file],
        'Missing original backup for '.$file);
}
product01Assert(hash_file('sha256', $target.'/routes/api.php') === $originalHashes['routes/api.php'],
    'Out-of-manifest API routes changed');
product01Assert(hash_file('sha256', $target.'/.env') === $originalHashes['.env'],
    'Private environment changed');
product01Assert(hash_file('sha256', $target.'/private-db-sentinel.txt') === $originalHashes['private-db-sentinel.txt'],
    'Database sentinel changed');
product01Assert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'),
    'database/migrations/2026_10_23_000008_add_saas_cj_product_fetch_throttle.php'), 'Rollback list missing migration');
echo "PASS: isolated apply uses private backups, only scoped files and one additive migration stub.\n";
echo "PASS: PRODUCT-01 selective installer and rollback checks.\n";
