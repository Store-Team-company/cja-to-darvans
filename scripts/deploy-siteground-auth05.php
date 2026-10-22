<?php
/**
 * AUTH-05: SiteGround changes-only password-login release.
 * Never overwrites unknown live files or touches credentials / existing data.
 * Usage: --check --target=... OR --apply --backup-confirmed --expected-head=<40hex> --target=...
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function auth05Abort(string $reason): never
{
    fwrite(STDERR, "AUTH-05 ABORT: {$reason}\n");
    exit(2);
}

function auth05Git(string $root, array $args): array
{
    $process = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (! is_resource($process)) {
        auth05Abort('Git cannot run.');
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out];
}

$arg = static function (string $name) use ($argv): string {
    foreach ($argv as $value) {
        if (str_starts_with($value, $name.'=')) {
            return substr($value, strlen($name) + 1);
        }
    }

    return '';
};
$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($check === $apply) {
    auth05Abort('Specify --check or --apply (only one).');
}
$source = realpath(dirname(__DIR__));
$target = realpath($arg('--target'));
if (! $source || ! $target || $source === $target
    || basename($target) !== 'data-pilot-app'
    || ! is_file($target.'/.siteground-layout')
    || ! is_file($target.'/.env')
    || ! is_file($target.'/artisan')
    || ! is_file($target.'/vendor/autoload.php')
    || ! is_dir(dirname($target).'/public_html')) {
    auth05Abort('Target must be verified existing PRIVATE Data Pilot installation.');
}
[$rc, $branch] = auth05Git($source, ['symbolic-ref', '--short', 'HEAD']);
[$rcHead, $head] = auth05Git($source, ['rev-parse', 'HEAD']);
[$rcStatus, $dirty] = auth05Git($source, ['status', '--porcelain']);
$head = trim($head);
if ($rc || $rcHead || $rcStatus || trim($branch) !== 'fix/datapilot-auth-05-safe-live-route-merge'
    || trim($dirty) !== '' || ! preg_match('/^[a-f0-9]{40}$/D', $head)) {
    auth05Abort('Source must be a clean checkout on the reviewed AUTH-05 feature branch.');
}

$base = '1dba1784083c8e4a9bb6c765c3e7ff12fd8d38d7';
if (auth05Git($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    auth05Abort('Source does not descend from deployed AUTH-04.');
}
if ($apply && (! in_array('--backup-confirmed', $argv, true)
    || ! hash_equals($head, $arg('--expected-head')))) {
    auth05Abort('Apply requires --backup-confirmed and exact --expected-head.');
}

foreach ([
    'app/Services/Saas/SaasEmailOtpService.php',
    'database/migrations/2026_10_22_000003_create_saas_email_otp_challenges_table.php',
] as $required) {
    if (! is_file($target.'/'.$required)) {
        auth05Abort('AUTH-04/AUTH-03 prerequisite missing: '.$required);
    }
}

/**
 * Preserve unrelated SiteGround customizations in the two shared legacy
 * admin files. Only insert a tightly scoped anchor-checked feature delta.
 * An absent/ambiguous anchor or a pre-existing conflicting route is fatal.
 */
function auth05MergeCustomized(string $path, string $live): string
{
    if ($path === 'routes/balenco-data-pilot.php') {
        if (str_contains($live, "->name('saas.customers')") ||
            str_contains($live, "->name('saas.customers.approve')")) {
            auth05Abort('Existing customer-admin routes require manual review.');
        }

        $anchor = "    Route::get('/profile', [BdpProfileController::class, 'index'])->name('profile');";
        $addition = <<<'PHP'
    // AUTH-05 customer activation: legacy platform-admin session only.
    Route::get('/saas-customers', [\App\Http\Controllers\Saas\SaasCustomerAdminController::class, 'index'])
        ->middleware(\App\Http\Middleware\EnsureSaasEnabled::class)->name('saas.customers');
    Route::post('/saas-customers/{customer}/approve', [\App\Http\Controllers\Saas\SaasCustomerAdminController::class, 'approve'])
        ->middleware([\App\Http\Middleware\EnsureSaasEnabled::class, 'throttle:10,1'])
        ->name('saas.customers.approve');

PHP;

        if (substr_count($live, $anchor) !== 1 ||
            substr_count($live, "Route::middleware([BalencoDataPilotAuth::class])") !== 1 ||
            strpos($live, $anchor) < strpos($live, "Route::middleware([BalencoDataPilotAuth::class])")) {
            auth05Abort('Missing/ambiguous legacy admin route insertion point.');
        }

        return str_replace($anchor, $addition.$anchor, $live);
    }

    if ($path === 'resources/views/balenco-data-pilot/layout.blade.php') {
        if (str_contains($live, "bdp.saas.customers")) {
            auth05Abort('Existing customer-account nav item requires manual review.');
        }
        $anchor = "            ['label' => 'Admin Profile', 'route' => 'bdp.profile', 'match' => 'bdp.profile*', 'icon' => 'user'],";
        $nav = "            ['label' => 'Customer Accounts', 'route' => 'bdp.saas.customers', 'match' => 'bdp.saas.customers*', 'icon' => 'user'],\n";
        $initials = '    $bdpInitials =';
        $guard = <<<'PHP'
    // Keep a disabled SaaS module out of the legacy admin navigation.
    if (!config('saas.enabled', false)) {
        $navGroups['Administration'] = array_values(array_filter(
            $navGroups['Administration'],
            fn ($item) => $item['route'] !== 'bdp.saas.customers'
        ));
    }

PHP;
        if (substr_count($live, $anchor) !== 1 ||
            substr_count($live, $initials) !== 1 ||
            strpos($live, $initials) < strpos($live, $anchor)) {
            auth05Abort('Missing/ambiguous legacy admin navigation insertion point.');
        }
        $live = str_replace($anchor, $nav.$anchor, $live);

        return str_replace($initials, $guard.$initials, $live);
    }

    auth05Abort('Attempted merge of unauthorized file.');
}

// Replaced files are accepted ONLY if unchanged since deployed AUTH-04,
// or if they already exactly match this reviewed AUTH-05 checkout.
$files = [
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Models/User.php',
    'app/Services/Saas/SaasAccountAccess.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'routes/saas.php',
    'routes/balenco-data-pilot.php',
    'resources/views/saas/auth/login.blade.php',
    'resources/views/saas/auth/register.blade.php',
    'resources/views/saas/auth/change-password.blade.php',
    'resources/views/balenco-data-pilot/saas-customers.blade.php',
    'resources/views/balenco-data-pilot/layout.blade.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
];
$changes = [];
foreach ($files as $path) {
    if (str_contains($path, '..') || ! preg_match('/^[A-Za-z0-9_\.\/\-]+$/D', $path)) {
        auth05Abort('Unsafe manifest path.');
    }
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    $parent = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $parent .= '/'.$part;
        if (is_link($parent)) {
            auth05Abort('Symlinked target parent: '.$path);
        }
    }
    if (! is_file($from) || is_link($from) || is_link($to)) {
        auth05Abort('Invalid source or target file: '.$path);
    }

    $live = is_file($to) ? hash_file('sha256', $to) : null;
    $new = hash_file('sha256', $from);
    [$baseExists, $old] = auth05Git($source, ['show', $base.':'.$path]);
    $merged = null;
    if ($live === $new) {
        $action = 'unchanged';
    } elseif ($baseExists === 0 && $live !== null && hash_equals(hash('sha256', $old), $live)) {
        $action = 'replace';
    } elseif ($baseExists !== 0 && $live === null) {
        $action = 'add';
    } elseif ($live !== null && in_array($path, [
        'routes/balenco-data-pilot.php',
        'resources/views/balenco-data-pilot/layout.blade.php',
    ], true)) {
        $merged = auth05MergeCustomized($path, (string) file_get_contents($to));
        $action = 'merge';
    } else {
        auth05Abort('Live file customized or unexpected: '.$path.'. Refusing overwrite.');
    }
    $beforeHash = $live;
    $changes[] = compact('path', 'from', 'to', 'action', 'merged', 'beforeHash');
}

echo "AUTH-05 exact source: {$head}\n";
foreach ($changes as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, product data, vendor, public_html or pre-existing migration modifications.\n";
if ($check) {
    echo "PASS: read-only preflight; no filesystem or database changes.\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/auth05-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (! mkdir($backup, 0700, true)) {
    auth05Abort('Cannot create private rollback folder.');
}
$added = [];
foreach ($changes as $item) {
    if (in_array($item['action'], ['replace', 'merge'], true)) {
        $dest = $backup.'/'.$item['path'];
        if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0700, true)) {
            auth05Abort('Cannot create backup subfolder.');
        }
        if (! copy($item['to'], $dest)) {
            auth05Abort('Cannot preserve original live file.');
        }
    } elseif ($item['action'] === 'add') {
        $added[] = $item['path'];
    }
}
file_put_contents($backup.'/ADDED_FILES.txt', implode("\n", $added)."\n", LOCK_EX);
echo "Private rollback folder: {$backup}\n";

foreach ($changes as $item) {
    if ($item['action'] === 'unchanged') {
        continue;
    }
    if (! is_dir(dirname($item['to'])) && ! mkdir(dirname($item['to']), 0755, true)) {
        auth05Abort('Cannot create application directory.');
    }
    // Refuse a time-of-check/time-of-use file change since this run's preflight.
    $currentHash = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($currentHash !== $item['beforeHash']) {
        auth05Abort('Target changed during deployment; restore from private backup.');
    }
    $tmp = tempnam(dirname($item['to']), '.auth05-');
    $copied = $tmp && ($item['action'] === 'merge'
        ? file_put_contents($tmp, $item['merged'], LOCK_EX) !== false
        : copy($item['from'], $tmp));
    if (! $copied || ! chmod($tmp, 0644) || ! rename($tmp, $item['to'])) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        auth05Abort('Deployment interrupted; restore private backup.');
    }
    echo 'Installed '.$item['path']."\n";
}

foreach ([
    ['migrate', '--force', '--no-interaction',
        '--path=database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php'],
    ['optimize:clear'],
] as $artisanArgs) {
    $process = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $artisanArgs), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $target);
    if (! is_resource($process)) {
        auth05Abort('Unable to execute Laravel artisan; rollback from private backup.');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        auth05Abort('Migration/cache step failed; restore reviewed source from private backup.');
    }
}
echo "AUTH-05 installed. Browser acceptance / account activation still required.\n";
