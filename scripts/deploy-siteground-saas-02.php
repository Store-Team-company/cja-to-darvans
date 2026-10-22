<?php
/**
 * Data Pilot SaaS-02 safe changes-only CLI deployment.
 *
 * From the reviewed feature branch checkout:
 *   php scripts/deploy-siteground-saas-02.php --check --target=/absolute/path/data-pilot-app
 *   php scripts/deploy-siteground-saas-02.php --apply --backup-confirmed --target=/absolute/path/data-pilot-app
 *
 * Public signup/billing flags remain off. Live importer data is untouched.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function abortDeploy(string $message): never
{
    fwrite(STDERR, "ABORT: {$message}\n");
    exit(2);
}

function gitOutput(string $repo, array $arguments): string
{
    $process = proc_open(array_merge(['git'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
    if (!is_resource($process)) {
        abortDeploy('Git is not available.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($out)) {
        abortDeploy('Git source verification failed. '.$err);
    }
    return $out;
}

$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($check === $apply || ($apply && !in_array('--backup-confirmed', $argv, true))) {
    abortDeploy('Select --check OR --apply --backup-confirmed after an independent database/site backup.');
}
$targetArg = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $targetArg = substr($arg, 9);
    }
}
$repo = realpath(dirname(__DIR__));
$target = $targetArg !== '' ? realpath($targetArg) : false;
if (!$repo || !$target || $repo === $target || basename($target) !== 'data-pilot-app'
    || !is_file($target.'/.siteground-layout')
    || !is_file($target.'/.env') || !is_file($target.'/artisan')
    || !is_file($target.'/vendor/autoload.php')
    || !is_dir(dirname($target).'/public_html')) {
    abortDeploy('Target must be the existing private SiteGround Data Pilot installation.');
}
$expectedBranch = 'feature/datapilot-saas-02-portal-stripe-billing';
$branch = trim(gitOutput($repo, ['symbolic-ref', '--short', 'HEAD']));
if ($branch !== $expectedBranch || trim(gitOutput($repo, ['status', '--porcelain'])) !== '') {
    abortDeploy('Checkout must be clean and on reviewed branch '.$expectedBranch);
}

// Exact approved parent of the new SaaS portal changes.
$base = 'b64811521d43b4575f840974b1fc08306f9542a9';
$files = [
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'app/Http/Controllers/Saas/SaasBillingController.php',
    'app/Http/Controllers/Saas/SaasBillingWebhookController.php',
    'app/Http/Controllers/Saas/SaasConnectionController.php',
    'app/Http/Controllers/Saas/SaasPortalController.php',
    'app/Http/Controllers/Saas/SaasPublicController.php',
    'app/Http/Controllers/Saas/WorkspaceDashboardController.php',
    'app/Http/Middleware/EnsureSaasEntitlement.php',
    'app/Models/SaasWorkspace.php',
    'app/Models/SaasWorkspaceBilling.php',
    'app/Services/Saas/SaasEntitlements.php',
    'app/Services/Saas/SaasPlanCatalog.php',
    'app/Services/Saas/SaasStripeGateway.php',
    'app/Services/Saas/SaasStripeWebhookProcessor.php',
    'config/saas.php',
    'database/migrations/2026_10_22_000002_create_saas_billing_and_webhook_events.php',
    'resources/views/saas/auth/forgot-password.blade.php',
    'resources/views/saas/auth/login.blade.php',
    'resources/views/saas/auth/register.blade.php',
    'resources/views/saas/auth/reset-password.blade.php',
    'resources/views/saas/auth/verify-email.blade.php',
    'resources/views/saas/billing.blade.php',
    'resources/views/saas/connections.blade.php',
    'resources/views/saas/dashboard.blade.php',
    'resources/views/saas/layout.blade.php',
    'resources/views/saas/products.blade.php',
    'resources/views/saas/public/home.blade.php',
    'resources/views/saas/public/pricing.blade.php',
    'resources/views/saas/settings.blade.php',
    'resources/views/saas/team.blade.php',
    'routes/api.php',
    'routes/saas.php'
];
$operations = [];
foreach ($files as $rel) {
    if (!preg_match('/^[A-Za-z0-9_\-\/.]+$/D', $rel) || str_contains($rel, '..')) {
        abortDeploy('Unsafe path in update manifest.');
    }
    $source = $repo.'/'.$rel;
    $destination = $target.'/'.$rel;
    if (!is_file($source) || is_link($source) || is_link($destination)) {
        abortDeploy('Missing or symlinked source: '.$rel);
    }
    $parent = $target;
    foreach (array_slice(explode('/', $rel), 0, -1) as $part) {
        $parent .= '/'.$part;
        if (is_link($parent)) {
            abortDeploy('Refusing symlink parent: '.$rel);
        }
    }
    $expectedNew = hash_file('sha256', $source);
    $current = is_file($destination) ? hash_file('sha256', $destination) : null;
    $isNew = !str_contains(
        trim(gitOutput($repo, ['ls-tree', '--name-only', $base, '--', $rel])),
        $rel
    );
    if ($current === $expectedNew) {
        $mode = 'unchanged';
    } elseif ($isNew && $current === null) {
        $mode = 'new';
    } elseif (!$isNew && $current !== null
        && hash('sha256', gitOutput($repo, ['show', $base.':'.$rel])) === $current) {
        $mode = 'replace';
    } else {
        abortDeploy('Existing file does not match the approved base: '.$rel
            .'. Stop and review before overwriting custom server changes.');
    }
    $operations[] = compact('rel', 'source', 'destination', 'mode');
}

$env = (string) file_get_contents($target.'/.env');
foreach (['SAAS_BILLING_ENABLED', 'SAAS_REGISTRATION_ENABLED'] as $flag) {
    if (preg_match('/^\s*'.preg_quote($flag, '/').'\s*=\s*(true|1|yes)\s*$/im', $env)) {
        abortDeploy('Disable public signup and billing first; '.$flag.' must remain false during this staged update.');
    }
}

$needed = count(array_filter($operations, fn (array $op) => $op['mode'] !== 'unchanged'));
echo "Data Pilot SaaS-02 customer portal update\n";
echo 'Preflight PASS: '.count($files).' scoped files checked; '.$needed." files to update.\n";
echo "All existing importer records, .env, vendor, storage and public_html remain untouched.\n";
echo "Adds the saas_workspace_billing and saas_stripe_events tables only.\n";
if ($check) {
    echo "Dry run only. No server files or database records changed.\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/saas-02-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    abortDeploy('Could not create private backup directory.');
}
$addedFiles = [];
foreach ($operations as $op) {
    if ($op['mode'] === 'replace') {
        $backupFile = $backup.'/'.$op['rel'];
        if (!is_dir(dirname($backupFile)) && !mkdir(dirname($backupFile), 0700, true)) {
            abortDeploy('Cannot create backup subfolder.');
        }
        if (!copy($op['destination'], $backupFile)) {
            abortDeploy('Source backup failed; no files replaced.');
        }
    } elseif ($op['mode'] === 'new') {
        $addedFiles[] = $op['rel'];
    }
}
file_put_contents($backup.'/ADDED_FILES.txt', implode("\n", $addedFiles)."\n", LOCK_EX);
echo 'Original source backup: '.$backup."\n";

foreach ($operations as $op) {
    if ($op['mode'] === 'unchanged') {
        continue;
    }
    $dir = dirname($op['destination']);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        abortDeploy('Could not create app directory '.$op['rel']);
    }
    $temporary = tempnam($dir, '.datapilot-saas02-');
    if (!$temporary || !copy($op['source'], $temporary) || !chmod($temporary, 0644)
        || !rename($temporary, $op['destination'])) {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        abortDeploy('Failed copying '.$op['rel'].'; restore from printed backup directory.');
    }
    echo 'Installed '.$op['rel']."\n";
}

function artisan(string $directory, array $arguments): void
{
    $proc = proc_open(
        array_merge([PHP_BINARY, $directory.'/artisan'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $directory
    );
    if (!is_resource($proc)) {
        abortDeploy('Cannot start artisan.');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        abortDeploy('Artisan command failed; keep public signup/billing disabled and inspect migration.');
    }
}
artisan($target, [
    'migrate', '--force', '--no-interaction',
    '--path=database/migrations/2026_10_22_000002_create_saas_billing_and_webhook_events.php',
]);
artisan($target, ['optimize:clear']);
echo "SUCCESS: SaaS-02 source installed; public registration and Stripe billing remain disabled.\n";
