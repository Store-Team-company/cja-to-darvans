<?php

/** BILLING-03 changes-only SiteGround safety checks on an isolated fixture. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '5e327a5f587d7e205cb6f1a76ccc16322307f62a';
$tmpRoot = sys_get_temp_dir().'/dp-billing03-'.bin2hex(random_bytes(6));
$target = $tmpRoot.'/www/staging.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-billing03.php';

function billing03Assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('BILLING-03 staging smoke FAIL: '.$message);
    }
}
function billing03Exec(array $args, string $cwd): array
{
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    billing03Assert(is_resource($process), 'Unable to start test command');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}
function billing03Write(string $path, string $content): void
{
    billing03Assert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'mkdir fixture');
    billing03Assert(file_put_contents($path, $content, LOCK_EX) !== false, 'write fixture');
}
function billing03Run(array $args): array
{
    global $root, $installer, $target;
    return billing03Exec([PHP_BINARY, $installer, ...$args, '--target='.$target], $root);
}
function billing03Reject(array $args, string $pattern): void
{
    [$code, $result] = billing03Run($args);
    billing03Assert($code !== 0 && str_contains($result, $pattern), 'expected abort: '.$pattern.' '.$result);
}

billing03Assert(mkdir($target.'/vendor', 0700, true), 'vendor fixture');
billing03Assert(mkdir(dirname($target).'/public_html', 0700, true), 'docroot fixture');
foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $path) {
    billing03Write($target.'/'.$path, 'private-test-fixture');
}
billing03Write($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);

$replaced = [
    'app/Services/Saas/SaasEntitlements.php',
    'app/Http/Controllers/Saas/SaasBillingController.php',
    'resources/views/saas/billing.blade.php',
    'routes/balenco-data-pilot.php',
    'resources/views/balenco-data-pilot/saas-customers.blade.php',
    'app/Http/Controllers/Saas/SaasPortalController.php',
    'app/Http/Controllers/Saas/WorkspaceDashboardController.php',
    'resources/views/saas/layout.blade.php',
    'resources/views/saas/dashboard.blade.php',
    'config/saas.php',
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
    'database/migrations/2026_10_23_000008_add_saas_cj_product_fetch_throttle.php',
    'app/Services/Saas/SaasCjProductDraftImporter.php',
    'app/Http/Controllers/Saas/SaasCjProductController.php',
];
foreach (array_merge($replaced, $prerequisites, ['routes/api.php']) as $file) {
    [$status, $contents] = billing03Exec(['git', 'show', $base.':'.$file], $root);
    billing03Assert($status === 0, 'Baseline missing '.$file);
    billing03Write($target.'/'.$file, $contents);
}
billing03Write($target.'/private-db-sentinel.txt', 'untouched');
$originalHashes = [];
foreach (array_merge($replaced, $prerequisites, ['routes/api.php', '.env', 'artisan', 'private-db-sentinel.txt']) as $file) {
    $originalHashes[$file] = hash_file('sha256', $target.'/'.$file);
}

[$status, $output] = billing03Run(['--check']);
billing03Assert($status === 0 && str_contains($output, 'PASS: read-only BILLING-03 preflight'),
    'Preflight failed: '.$output);
billing03Assert(str_contains($output, 'replace routes/balenco-data-pilot.php')
    && str_contains($output, 'add app/Models/SaasAdminPlanGrant.php')
    && str_contains($output, 'add database/migrations/2026_10_23_000009_create_saas_admin_plan_grants.php'),
    'Manifest incorrect');
foreach ($originalHashes as $file => $hash) {
    billing03Assert(hash_file('sha256', $target.'/'.$file) === $hash, 'Read-only check modified '.$file);
}
billing03Assert(!is_dir(dirname($target).'/datapilot-private-backups'), '--check unexpectedly backed up');
echo "PASS: check is read-only for files, database sentinel, and environment.\n";

[$status, $sha] = billing03Exec(['git', 'rev-parse', 'HEAD'], $root);
billing03Assert($status === 0, 'Unable to identify source HEAD');
$sha = trim($sha);
billing03Reject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'Apply requires');
billing03Reject(['--apply', '--expected-head='.$sha], 'Apply requires');
billing03Reject(['--apply', '--backup-confirmed'], 'Apply requires');
echo "PASS: apply requires backup confirmation and exact reviewed SHA.\n";

$route = $target.'/routes/balenco-data-pilot.php';
$original = file_get_contents($route);
billing03Write($route, $original."\n// Customer-only customization; must never be overwritten.\n");
$hash = hash_file('sha256', $route);
billing03Reject(['--check'], 'SiteGround customization in routes/balenco-data-pilot.php');
billing03Assert(hash_file('sha256', $route) === $hash, 'Refusal modified a customized live file');
billing03Write($route, $original);

unlink($route);
billing03Reject(['--check'], 'SiteGround customization in routes/balenco-data-pilot.php');
billing03Write($route, $original);
rename($route, $target.'/routes/saas-original.php');
symlink($target.'/routes/api.php', $route);
billing03Reject(['--check'], 'Missing/unsafe source or symlink');
unlink($route);
rename($target.'/routes/saas-original.php', $route);
echo "PASS: missing, customized and symlinked live manifest files are protected.\n";

$needed = $target.'/'.$prerequisites[0];
$body = file_get_contents($needed);
unlink($needed);
billing03Reject(['--check'], 'Prerequisite DataPilot SaaS code missing');
billing03Write($needed, $body);

$unknown = $tmpRoot.'/invalid-installation';
mkdir($unknown, 0700, true);
[$status, $out] = billing03Exec([PHP_BINARY, $installer, '--check', '--target='.$unknown], $root);
billing03Assert($status !== 0 && str_contains($out, 'Unrecognized private DataPilot SiteGround installation'),
    'Unknown installation accepted');
echo "PASS: missing prerequisites and unknown installation refused.\n";

$tracked = $root.'/docs/DATAPILOT_CJ_01_QA_AND_SITEGROUND.md';
$trackedOriginal = file_get_contents($tracked);
try {
    billing03Write($tracked, $trackedOriginal."\n<!-- dirty test -->\n");
    billing03Reject(['--check'], 'Use a clean reviewed BILLING-03 checkout');
} finally {
    billing03Write($tracked, $trackedOriginal);
}
billing03Assert(hash_file('sha256', $tracked) === hash('sha256', $trackedOriginal), 'Dirty fixture not cleaned');
echo "PASS: dirty source tree refused.\n";

[$status, $output] = billing03Run(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
billing03Assert($status === 0 && str_contains($output, 'BILLING-03 optional admin grants installed'), 'Sandbox apply failed: '.$output);
billing03Assert(str_contains($output, '2026_10_23_000009_create_saas_admin_plan_grants.php'),
    'Additive migration not dispatched');
preg_match('/Private rollback backup: ([^\n]+)/', $output, $matches);
$backup = $matches[1] ?? '';
billing03Assert($backup !== '' && is_dir($backup) && !str_contains($backup, '/public_html/'),
    'Backup not private');
foreach ($replaced as $file) {
    billing03Assert(is_file($backup.'/'.$file)
        && hash_file('sha256', $backup.'/'.$file) === $originalHashes[$file],
        'Missing original backup for '.$file);
}
billing03Assert(hash_file('sha256', $target.'/routes/api.php') === $originalHashes['routes/api.php'],
    'Out-of-manifest API routes changed');
billing03Assert(hash_file('sha256', $target.'/.env') === $originalHashes['.env'],
    'Private environment changed');
billing03Assert(hash_file('sha256', $target.'/private-db-sentinel.txt') === $originalHashes['private-db-sentinel.txt'],
    'Database sentinel changed');
billing03Assert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'),
    'database/migrations/2026_10_23_000009_create_saas_admin_plan_grants.php'), 'Rollback list missing migration');
echo "PASS: isolated apply uses private backups, only scoped files and one additive migration stub.\n";
echo "PASS: BILLING-03 selective installer and rollback checks.\n";
