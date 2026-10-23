<?php

/**
 * DataPilot BILLING-03: hash-locked SaaS staging update for secure CJ authentication.
 * This script does NOT deploy/modify the Botble site or .env.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function billing03Abort(string $message): never
{
    fwrite(STDERR, "BILLING-03 ABORT: {$message}\n");
    exit(2);
}

function billing03Git(string $cwd, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (! is_resource($proc)) {
        billing03Abort('Git unavailable');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out];
}

$get = static function (string $name) use ($argv): string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name.'=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return '';
};
$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($check === $apply) {
    billing03Abort('Specify exactly one of --check or --apply');
}
$source = realpath(dirname(__DIR__));
$target = realpath($get('--target'));
if (! $source || ! $target || $source === $target
    || basename($target) !== 'data-pilot-app'
    || ! is_file($target.'/.siteground-layout')
    || ! is_file($target.'/.env')
    || ! is_file($target.'/artisan')
    || ! is_file($target.'/vendor/autoload.php')
    || ! is_dir(dirname($target).'/public_html')) {
    billing03Abort('Unrecognized private DataPilot SiteGround installation');
}

[$rc, $branch] = billing03Git($source, ['symbolic-ref', '--short', 'HEAD']);
[$rcHead, $head] = billing03Git($source, ['rev-parse', 'HEAD']);
[$rcStatus, $dirty] = billing03Git($source, ['status', '--porcelain']);
$head = trim($head);
if ($rc !== 0 || $rcHead !== 0 || $rcStatus !== 0
    || trim($branch) !== 'feature/datapilot-billing-03-admin-grants'
    || trim($dirty) !== ''
    || ! preg_match('/^[a-f0-9]{40}$/D', $head)) {
    billing03Abort('Use a clean reviewed BILLING-03 checkout, not main/another feature');
}
$base = '5e327a5f587d7e205cb6f1a76ccc16322307f62a';
if (billing03Git($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    billing03Abort('BILLING-03 must descend from reviewed PRODUCT-01 base');
}
if ($apply && (! in_array('--backup-confirmed', $argv, true)
    || ! hash_equals($head, $get('--expected-head')))) {
    billing03Abort('Apply requires --backup-confirmed and --expected-head=EXACT_SHA');
}

foreach ([
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Services/Saas/SaasFirstPartyPilot.php',
    'app/Services/Saas/SaasCjApiClient.php',
    'app/Services/Saas/SaasCjPilot.php',
    'database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php',
    'database/migrations/2026_10_23_000007_guard_saas_cj_credential_revisions.php',
    'database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php',
] as $prerequisite) {
    if (! is_file($target.'/'.$prerequisite)) {
        billing03Abort('Prerequisite DataPilot SaaS code missing: '.$prerequisite);
    }
}


/**
 * A customized legacy admin routes file must never be replaced wholesale.
 * Insert only the three grant routes inside the existing BDP-authenticated group.
 * Refuse duplicate, missing, relocated or ambiguous route declarations.
 */
function billing03MergeAdminRoutes(string $live): string
{
    $group = "Route::middleware([BalencoDataPilotAuth::class])->name('bdp.')->group(function () {";
    $anchor = "    Route::get('/profile', [BdpProfileController::class, 'index'])->name('profile');";
    foreach ([
        "Route::get('/saas-workspaces'",
        "Route::post('/saas-workspaces/",
        "->name('saas.workspaces')",
        "->name('saas.workspaces.grant')",
        "->name('saas.workspaces.revoke')",
    ] as $conflict) {
        if (str_contains($live, $conflict)) {
            billing03Abort('Existing admin grant routes in customized SiteGround file; manual review required');
        }
    }
    if (substr_count($live, $group) !== 1 || substr_count($live, $anchor) !== 1
        || strpos($live, $anchor) <= strpos($live, $group)) {
        billing03Abort('Missing/ambiguous BDP admin route anchor; manual review required');
    }

    $addition = <<<'ROUTES'
    // BILLING-03: complimentary grants, inside existing BDP administrator auth.
    Route::get('/saas-workspaces', [\App\Http\Controllers\Saas\SaasAdminPlanGrantController::class, 'index'])
        ->middleware(\App\Http\Middleware\EnsureSaasEnabled::class)->name('saas.workspaces');
    Route::post('/saas-workspaces/{workspace:public_id}/grant', [\App\Http\Controllers\Saas\SaasAdminPlanGrantController::class, 'grant'])
        ->middleware([\App\Http\Middleware\EnsureSaasEnabled::class, 'throttle:5,1'])
        ->name('saas.workspaces.grant');
    Route::post('/saas-workspaces/{workspace:public_id}/revoke', [\App\Http\Controllers\Saas\SaasAdminPlanGrantController::class, 'revoke'])
        ->middleware([\App\Http\Middleware\EnsureSaasEnabled::class, 'throttle:5,1'])
        ->name('saas.workspaces.revoke');

ROUTES;

    return str_replace($anchor, $addition.$anchor, $live);
}

$manifest = [
    'app/Models/SaasAdminPlanGrant.php',
    'app/Http/Controllers/Saas/SaasAdminPlanGrantController.php',
    'resources/views/balenco-data-pilot/saas-workspaces.blade.php',
    'database/migrations/2026_10_23_000009_create_saas_admin_plan_grants.php',
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

$plan = [];
foreach ($manifest as $path) {
    if (! preg_match('/^[A-Za-z0-9_.\/-]+$/D', $path) || str_contains($path, '..')) {
        billing03Abort('Unsafe path');
    }
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (! is_file($from) || is_link($from) || is_link($to)) {
        billing03Abort('Missing/unsafe source or symlink: '.$path);
    }

    $parent = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $parent .= '/'.$part;
        if (is_link($parent)) {
            billing03Abort('Symlink target parent: '.$path);
        }
    }

    $before = is_file($to) ? hash_file('sha256', $to) : null;
    $after = hash_file('sha256', $from);
    [$rcOld, $oldContent] = billing03Git($source, ['show', $base.':'.$path]);
    if ($before === $after) {
        $action = 'unchanged';
    } elseif ($rcOld === 0 && $before !== null
        && hash_equals(hash('sha256', $oldContent), $before)) {
        $action = 'replace';
    } elseif ($rcOld !== 0 && $before === null) {
        $action = 'add';
    } elseif ($path === 'routes/balenco-data-pilot.php' && $before !== null) {
        $action = 'merge';
        $merged = billing03MergeAdminRoutes((string) file_get_contents($to));
    } else {
        billing03Abort('SiteGround customization in '.$path.'; refuses unsafe overwrite');
    }
    $plan[] = compact('path', 'from', 'to', 'before', 'action') + ['merged' => $merged ?? null];
    unset($merged);
}

echo "BILLING-03 source commit: {$head}\n";
foreach ($plan as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, credentials, admin auth, customer data, Stripe, CJ, Botble vendor or public_html changes\n";

if ($check) {
    echo "PASS: read-only BILLING-03 preflight; no changes made\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/billing03-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (! mkdir($backup, 0700, true)) {
    billing03Abort('Unable to create private backup folder');
}
$added = [];
foreach ($plan as $item) {
    if (in_array($item['action'], ['replace', 'merge'], true)) {
        $dest = $backup.'/'.$item['path'];
        if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0700, true)) {
            billing03Abort('Cannot create backup subdirectory');
        }
        if (! copy($item['to'], $dest)) {
            billing03Abort('Failed to back up all original files; nothing installed');
        }
    } elseif ($item['action'] === 'add') {
        $added[] = $item['path'];
    }
}
file_put_contents($backup.'/ADDED_FILES.txt', implode("\n", $added)."\n", LOCK_EX);
echo "Private rollback backup: {$backup}\n";

foreach ($plan as $item) {
    if ($item['action'] === 'unchanged') {
        continue;
    }
    if (! is_dir(dirname($item['to'])) && ! mkdir(dirname($item['to']), 0755, true)) {
        billing03Abort('Cannot create target directory; use rollback backup');
    }
    $now = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($now !== $item['before']) {
        billing03Abort('SiteGround changed during deployment; use rollback backup');
    }
    $tmp = tempnam(dirname($item['to']), '.billing03-');
    if (! $tmp || ! ($item['action'] === 'merge'
            ? file_put_contents($tmp, $item['merged'], LOCK_EX) !== false
            : copy($item['from'], $tmp))
        || ! chmod($tmp, 0644) || ! rename($tmp, $item['to'])) {
        if ($tmp) {
            @unlink($tmp);
        }
        billing03Abort('Atomic file replacement failed; use rollback backup');
    }
    echo 'Installed '.$item['path']."\n";
}

foreach ([
    ['migrate', '--force', '--no-interaction', '--path=database/migrations/2026_10_23_000009_create_saas_admin_plan_grants.php'],
    ['optimize:clear'],
] as $params) {
    $proc = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $params), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $target);
    if (! is_resource($proc)) {
        billing03Abort('Artisan unavailable; use private rollback backup');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        billing03Abort('Artisan migration/cache command failed; use private rollback backup');
    }
}
echo "BILLING-03 optional admin grants installed. Feature flag OFF by default; no Stripe records were changed.\n";
