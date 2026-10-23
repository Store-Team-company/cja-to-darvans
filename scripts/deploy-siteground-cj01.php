<?php

/**
 * DataPilot CJ-01: hash-locked SaaS staging update for secure CJ authentication.
 * This script does NOT deploy/modify the Botble site or .env.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function cj01Abort(string $message): never
{
    fwrite(STDERR, "CJ-01 ABORT: {$message}\n");
    exit(2);
}

function cj01Git(string $cwd, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (! is_resource($proc)) {
        cj01Abort('Git unavailable');
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
    cj01Abort('Specify exactly one of --check or --apply');
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
    cj01Abort('Unrecognized private DataPilot SiteGround installation');
}

[$rc, $branch] = cj01Git($source, ['symbolic-ref', '--short', 'HEAD']);
[$rcHead, $head] = cj01Git($source, ['rev-parse', 'HEAD']);
[$rcStatus, $dirty] = cj01Git($source, ['status', '--porcelain']);
$head = trim($head);
if ($rc !== 0 || $rcHead !== 0 || $rcStatus !== 0
    || trim($branch) !== 'feature/datapilot-cj-01-secure-connection'
    || trim($dirty) !== ''
    || ! preg_match('/^[a-f0-9]{40}$/D', $head)) {
    cj01Abort('Use a clean reviewed CJ-01 checkout, not main/another feature');
}
$base = 'ac8dd7874409d39de68c34214e18c44416b1ae1a';
if (cj01Git($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    cj01Abort('CJ-01 must descend from password-login AUTH-05B');
}
if ($apply && (! in_array('--backup-confirmed', $argv, true)
    || ! hash_equals($head, $get('--expected-head')))) {
    cj01Abort('Apply requires --backup-confirmed and --expected-head=EXACT_SHA');
}

foreach ([
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Services/Saas/SaasFirstPartyPilot.php',
    'database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php',
] as $prerequisite) {
    if (! is_file($target.'/'.$prerequisite)) {
        cj01Abort('Required AUTH-05 code missing: '.$prerequisite);
    }
}

$manifest = [
    'app/Services/Saas/SaasCjApiClient.php',
    'app/Services/Saas/SaasCjApiException.php',
    'app/Services/Saas/SaasCjPilot.php',
    'app/Models/SaasCjConnection.php',
    'app/Http/Controllers/Saas/SaasConnectionController.php',
    'app/Http/Middleware/EnsureSaasEntitlement.php',
    'resources/views/saas/connections.blade.php',
    'routes/saas.php',
    'config/saas.php',
    'database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php',
];

$plan = [];
foreach ($manifest as $path) {
    if (! preg_match('/^[A-Za-z0-9_.\/-]+$/D', $path) || str_contains($path, '..')) {
        cj01Abort('Unsafe path');
    }
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (! is_file($from) || is_link($from) || is_link($to)) {
        cj01Abort('Missing/unsafe source or symlink: '.$path);
    }

    $parent = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $parent .= '/'.$part;
        if (is_link($parent)) {
            cj01Abort('Symlink target parent: '.$path);
        }
    }

    $before = is_file($to) ? hash_file('sha256', $to) : null;
    $after = hash_file('sha256', $from);
    [$rcOld, $oldContent] = cj01Git($source, ['show', $base.':'.$path]);
    if ($before === $after) {
        $action = 'unchanged';
    } elseif ($rcOld === 0 && $before !== null
        && hash_equals(hash('sha256', $oldContent), $before)) {
        $action = 'replace';
    } elseif ($rcOld !== 0 && $before === null) {
        $action = 'add';
    } else {
        cj01Abort('SiteGround customization in '.$path.'; refuses unsafe overwrite');
    }
    $plan[] = compact('path', 'from', 'to', 'before', 'action');
}

echo "CJ-01 source commit: {$head}\n";
foreach ($plan as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, credentials, admin auth, customer data, Stripe, CJ, Botble vendor or public_html changes\n";

if ($check) {
    echo "PASS: read-only CJ-01 preflight; no changes made\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/cj01-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (! mkdir($backup, 0700, true)) {
    cj01Abort('Unable to create private backup folder');
}
$added = [];
foreach ($plan as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0700, true)) {
            cj01Abort('Cannot create backup subdirectory');
        }
        if (! copy($item['to'], $dest)) {
            cj01Abort('Failed to back up all original files; nothing installed');
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
        cj01Abort('Cannot create target directory; use rollback backup');
    }
    $now = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($now !== $item['before']) {
        cj01Abort('SiteGround changed during deployment; use rollback backup');
    }
    $tmp = tempnam(dirname($item['to']), '.cj01-');
    if (! $tmp || ! copy($item['from'], $tmp) || ! chmod($tmp, 0644)
        || ! rename($tmp, $item['to'])) {
        if ($tmp) {
            @unlink($tmp);
        }
        cj01Abort('Atomic file replacement failed; use rollback backup');
    }
    echo 'Installed '.$item['path']."\n";
}

foreach ([
    ['migrate', '--force', '--no-interaction', '--path=database/migrations/2026_10_23_000006_add_saas_cj_auth_state.php'],
    ['optimize:clear'],
] as $params) {
    $proc = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $params), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $target);
    if (! is_resource($proc)) {
        cj01Abort('Artisan unavailable; use private rollback backup');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        cj01Abort('Artisan migration/cache command failed; use private rollback backup');
    }
}
echo "CJ-01 staged code installed. CJ pilot is OFF until explicitly configured; live CJ authentication NOT verified.\n";
