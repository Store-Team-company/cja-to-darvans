<?php

/** CJ-01 installer safety contract. All targets are temporary local fixtures. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'ac8dd7874409d39de68c34214e18c44416b1ae1a';
$temp = sys_get_temp_dir().'/dp-cj01-'.bin2hex(random_bytes(6));
$target = $temp.'/www/test.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-cj01.php';

function cj01Assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('CJ-01 staging smoke FAIL: '.$message);
    }
}

function cj01Exec(array $args, string $cwd): array
{
    $process = proc_open($args, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    cj01Assert(is_resource($process), 'Cannot start test command');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $stdout.(string) $stderr];
}

function cj01Write(string $path, string $content): void
{
    cj01Assert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'Cannot create fixture directory');
    cj01Assert(file_put_contents($path, $content) !== false, 'Cannot write fixture');
}

function cj01Run(array $arguments): array
{
    global $installer, $target, $root;

    return cj01Exec([PHP_BINARY, $installer, ...$arguments, '--target='.$target], $root);
}

function cj01ExpectReject(array $arguments, string $expected): void
{
    [$status, $output] = cj01Run($arguments);
    cj01Assert($status !== 0 && str_contains($output, $expected),
        'Expected rejection ['.$expected.'] absent: '.$output);
}

function cj01Hashes(array $paths): array
{
    $snapshot = [];
    foreach ($paths as $p) {
        $snapshot[$p] = is_file($p) ? hash_file('sha256', $p) : null;
    }

    return $snapshot;
}

cj01Assert(mkdir($target.'/vendor', 0700, true), 'Cannot build fixture');
cj01Assert(mkdir(dirname($target).'/public_html', 0700, true), 'Cannot build document-root fixture');

foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $file) {
    cj01Write($target.'/'.$file, 'sentinel private fixture '.$file);
}
// The positive --apply uses a safe local stub instead of the actual Laravel
// migrations; no real network request or customer database is ever touched.
cj01Write($target.'/artisan', <<<'PHP'
<?php
echo "FIXTURE ARTISAN: ".implode(' ', array_slice($argv, 1))."\n";
PHP
);
$manifestBaseline = [
    'app/Models/SaasCjConnection.php',
    'app/Http/Controllers/Saas/SaasConnectionController.php',
    'app/Http/Middleware/EnsureSaasEntitlement.php',
    'resources/views/saas/connections.blade.php',
    'routes/saas.php',
    'config/saas.php',
];
$prerequisites = [
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Services/Saas/SaasFirstPartyPilot.php',
    'database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php',
];
$untouched = ['routes/api.php'];
foreach (array_merge($manifestBaseline, $prerequisites, $untouched) as $file) {
    [$status, $content] = cj01Exec(['git', 'show', $base.':'.$file], $root);
    cj01Assert($status === 0, 'Missing baseline: '.$file);
    cj01Write($target.'/'.$file, $content);
}
cj01Write($target.'/private-db-sentinel.txt', 'unchanged database marker');
$watched = array_map(fn ($p) => $target.'/'.$p, array_merge(
    $manifestBaseline, $prerequisites, $untouched,
    ['.env', '.siteground-layout', 'vendor/autoload.php', 'artisan', 'private-db-sentinel.txt']
));
$initialHashes = cj01Hashes($watched);
$initialFiles = count($watched);

[$status, $output] = cj01Run(['--check']);
cj01Assert($status === 0 && str_contains($output, 'PASS: read-only CJ-01 preflight'), 'Dry run: '.$output);
cj01Assert(str_contains($output, 'replace routes/saas.php')
    && str_contains($output, 'add app/Services/Saas/SaasCjApiClient.php')
    && str_contains($output, 'add database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php')
    && str_contains($output, 'add database/migrations/2026_10_23_000007_guard_saas_cj_credential_revisions.php'),
    'Manifest did not include exact expected entries');
cj01Assert($initialHashes === cj01Hashes($watched), '--check mutated an existing file');
cj01Assert(!is_dir(dirname($target).'/datapilot-private-backups'), '--check made a backup');
foreach ([
    'app/Services/Saas/SaasCjApiClient.php',
    'database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php',
    'database/migrations/2026_10_23_000007_guard_saas_cj_credential_revisions.php',
] as $added) {
    cj01Assert(!file_exists($target.'/'.$added), '--check installed an added file');
}
echo "PASS: dry-run left fixture, database sentinel, .env and all protected files unchanged.\n";

[$status, $head] = cj01Exec(['git', 'rev-parse', 'HEAD'], $root);
cj01Assert($status === 0 && preg_match('/^[a-f0-9]{40}$/D', trim($head)) === 1, 'Invalid CI HEAD');
$head = trim($head);
cj01ExpectReject(['--apply', '--target='.$target], '--expected-head');
cj01ExpectReject(['--apply', '--expected-head='.$head], '--backup-confirmed');
cj01ExpectReject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], '--expected-head');
cj01Assert($initialHashes === cj01Hashes($watched), 'Rejected --apply modified live files');
echo "PASS: apply requires both backup confirmation and exact source HEAD.\n";

// Deliberately alter a MANIFEST file, not routes/api.php (which CJ-01
// intentionally does not deploy). Every rejected operation must preserve it.
$route = $target.'/routes/saas.php';
$originalRoute = file_get_contents($route);
cj01Write($route, $originalRoute."\n// customer SiteGround-only customization; preserve exactly\n");
$tampered = hash_file('sha256', $route);
cj01ExpectReject(['--check'], 'SiteGround customization in routes/saas.php');
cj01Assert(hash_file('sha256', $route) === $tampered, 'Preflight changed customized live route');
cj01Write($route, $originalRoute);
echo "PASS: unknown customized manifest route rejected without modification.\n";

$required = $target.'/'.$prerequisites[0];
$requiredBody = file_get_contents($required);
unlink($required);
cj01ExpectReject(['--check'], 'Required AUTH-05 code missing');
cj01Write($required, $requiredBody);
unlink($route);
cj01ExpectReject(['--check'], 'SiteGround customization in routes/saas.php');
cj01Write($route, $originalRoute);
echo "PASS: missing prerequisites and missing existing manifest files rejected.\n";

// Target symlink rejection: the manifest contains only ordinary files.
cj01Assert(symlink($target.'/routes/api.php', $target.'/routes/saas-link.php'), 'Cannot prepare symlink');
rename($route, $target.'/routes/saas-original.php');
rename($target.'/routes/saas-link.php', $route);
cj01ExpectReject(['--check'], 'Missing/unsafe source or symlink');
cj01Assert(is_link($route), 'Rejected check overwrote the symlink');
unlink($route);
rename($target.'/routes/saas-original.php', $route);
echo "PASS: symlinked manifest paths rejected.\n";

// Dirty original checkout must reject before reading any manifest files.
$tracked = $root.'/docs/DATAPILOT_CJ_01_QA_AND_SITEGROUND.md';
$trackedOriginal = file_get_contents($tracked);
try {
    cj01Write($tracked, $trackedOriginal."\n<!-- verifier dirty source fixture -->\n");
    cj01ExpectReject(['--check'], 'Use a clean reviewed CJ-01 checkout');
} finally {
    cj01Write($tracked, $trackedOriginal);
}
cj01Assert(hash_file('sha256', $tracked) === hash('sha256', $trackedOriginal),
    'Verifier did not restore clean source');
echo "PASS: dirty source checkout rejected.\n";

// Worktree uses detached HEAD, therefore never matches reviewed source branch.
$wrong = $temp.'/detached-worktree';
[$workStatus, $workOutput] = cj01Exec(['git', 'worktree', 'add', '--detach', $wrong, $head], $root);
cj01Assert($workStatus === 0, 'Cannot create wrong-branch fixture: '.$workOutput);
try {
    [$status, $output] = cj01Exec([
        PHP_BINARY, $wrong.'/scripts/deploy-siteground-cj01.php', '--check', '--target='.$target,
    ], $wrong);
    cj01Assert($status !== 0 && str_contains($output, 'Use a clean reviewed CJ-01 checkout'),
        'Detached/wrong-branch source accepted: '.$output);
} finally {
    cj01Exec(['git', 'worktree', 'remove', '--force', $wrong], $root);
}
$unknown = $temp.'/unrecognized';
cj01Assert(mkdir($unknown, 0700), 'Cannot prepare unknown target');
[$status, $output] = cj01Exec([PHP_BINARY, $installer, '--check', '--target='.$unknown], $root);
cj01Assert($status !== 0 && str_contains($output, 'Unrecognized private DataPilot SiteGround installation'),
    'Unrecognized installation accepted');
echo "PASS: wrong branch and unknown installation paths rejected.\n";

// Positive sandbox-only apply: fake artisan prints the exact migration args,
// allowing verification of private backups before any real deployment.
[$status, $output] = cj01Run(['--apply', '--backup-confirmed', '--expected-head='.$head]);
cj01Assert($status === 0 && str_contains($output, 'CJ-01 staged code installed'), 'Sandbox apply: '.$output);
cj01Assert(str_contains($output, '2026_10_23_000006_add_saas_cj_auth_state.php')
    && str_contains($output, '2026_10_23_000007_guard_saas_cj_credential_revisions.php'),
    'Additive migrations not both dispatched');
preg_match('/Private rollback backup: ([^\n]+)/', $output, $match);
$backup = $match[1] ?? '';
cj01Assert($backup !== '' && is_dir($backup)
    && !str_contains($backup, '/public_html/'), 'Backup missing or publicly accessible');
foreach ($manifestBaseline as $file) {
    cj01Assert(is_file($backup.'/'.$file)
        && hash_file('sha256', $backup.'/'.$file) === $initialHashes[$target.'/'.$file],
        'Original manifest file not backed up before installation: '.$file);
}
cj01Assert(file_get_contents($target.'/routes/api.php') === file_get_contents($backup.'/../data-pilot-app/routes/api.php'),
    'Out-of-manifest API route changed');
cj01Assert($initialHashes[$target.'/private-db-sentinel.txt']
    === hash_file('sha256', $target.'/private-db-sentinel.txt'), 'Database sentinel changed');
cj01Assert(file_exists($backup.'/ADDED_FILES.txt')
    && str_contains(file_get_contents($backup.'/ADDED_FILES.txt'),
        'database/migrations/2026_10_23_000007_guard_saas_cj_credential_revisions.php'),
    'Rollback manifest lacks new migration');
echo "PASS: sandbox apply installed only selected files, preserved originals in private backup, ran additive migration stubs.\n";
echo "PASS: CJ-01 selective installer protections and rollback manifest verified.\n";
