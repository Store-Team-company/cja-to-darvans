<?php

/**
 * DataPilot BOTBLE-04: hash-locked SaaS staging update for first-party pairing.
 * This script does NOT deploy/modify the Botble site or .env.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function botble04Abort(string $message): never
{
    fwrite(STDERR, "BOTBLE-04 ABORT: {$message}\n");
    exit(2);
}

function botble04Git(string $cwd, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (! is_resource($proc)) {
        botble04Abort('Git unavailable');
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
    botble04Abort('Specify exactly one of --check or --apply');
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
    botble04Abort('Unrecognized private DataPilot SiteGround installation');
}

[$rc, $branch] = botble04Git($source, ['symbolic-ref', '--short', 'HEAD']);
[$rcHead, $head] = botble04Git($source, ['rev-parse', 'HEAD']);
[$rcStatus, $dirty] = botble04Git($source, ['status', '--porcelain']);
$head = trim($head);
if ($rc !== 0 || $rcHead !== 0 || $rcStatus !== 0
    || trim($branch) !== 'feature/datapilot-botble-04-password-pilot-pairing'
    || trim($dirty) !== ''
    || ! preg_match('/^[a-f0-9]{40}$/D', $head)) {
    botble04Abort('Use a clean reviewed BOTBLE-04 checkout, not main/another feature');
}
$base = '901424ebf486dd1291b81c28fd1c66e934b6cbf7';
if (botble04Git($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    botble04Abort('BOTBLE-04 must descend from password-login AUTH-05B');
}
if ($apply && (! in_array('--backup-confirmed', $argv, true)
    || ! hash_equals($head, $get('--expected-head')))) {
    botble04Abort('Apply requires --backup-confirmed and --expected-head=EXACT_SHA');
}

foreach ([
    'app/Http/Controllers/Saas/SaasCustomerAdminController.php',
    'app/Http/Middleware/EnsureSaasCustomerApproved.php',
    'database/migrations/2026_10_22_000004_add_manual_saas_customer_approval.php',
    'app/Services/Saas/SaasEmailOtpService.php',
] as $prerequisite) {
    if (! is_file($target.'/'.$prerequisite)) {
        botble04Abort('Required AUTH-05 code missing: '.$prerequisite);
    }
}

$manifest = [
    'app/Http/Controllers/Saas/SaasBotblePairingController.php',
    'app/Services/Saas/SaasFirstPartyPilot.php',
    'database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php',
    'app/Http/Controllers/Saas/SaasConnectionController.php',
    'app/Http/Middleware/EnsureSaasEntitlement.php',
    'resources/views/saas/connections.blade.php',
    'routes/api.php',
    'routes/saas.php',
    'config/saas.php',
];

$plan = [];
foreach ($manifest as $path) {
    if (! preg_match('/^[A-Za-z0-9_.\/-]+$/D', $path) || str_contains($path, '..')) {
        botble04Abort('Unsafe path');
    }
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (! is_file($from) || is_link($from) || is_link($to)) {
        botble04Abort('Missing/unsafe source or symlink: '.$path);
    }

    $parent = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $parent .= '/'.$part;
        if (is_link($parent)) {
            botble04Abort('Symlink target parent: '.$path);
        }
    }

    $before = is_file($to) ? hash_file('sha256', $to) : null;
    $after = hash_file('sha256', $from);
    [$rcOld, $oldContent] = botble04Git($source, ['show', $base.':'.$path]);
    if ($before === $after) {
        $action = 'unchanged';
    } elseif ($rcOld === 0 && $before !== null
        && hash_equals(hash('sha256', $oldContent), $before)) {
        $action = 'replace';
    } elseif ($rcOld !== 0 && $before === null) {
        $action = 'add';
    } else {
        botble04Abort('SiteGround customization in '.$path.'; refuses unsafe overwrite');
    }
    $plan[] = compact('path', 'from', 'to', 'before', 'action');
}

echo "BOTBLE-04 source commit: {$head}\n";
foreach ($plan as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, credentials, admin auth, customer data, Stripe, CJ, Botble vendor or public_html changes\n";

if ($check) {
    echo "PASS: read-only BOTBLE-04 preflight; no changes made\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/botble04-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (! mkdir($backup, 0700, true)) {
    botble04Abort('Unable to create private backup folder');
}
$added = [];
foreach ($plan as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0700, true)) {
            botble04Abort('Cannot create backup subdirectory');
        }
        if (! copy($item['to'], $dest)) {
            botble04Abort('Failed to back up all original files; nothing installed');
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
        botble04Abort('Cannot create target directory; use rollback backup');
    }
    $now = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($now !== $item['before']) {
        botble04Abort('SiteGround changed during deployment; use rollback backup');
    }
    $tmp = tempnam(dirname($item['to']), '.botble04-');
    if (! $tmp || ! copy($item['from'], $tmp) || ! chmod($tmp, 0644)
        || ! rename($tmp, $item['to'])) {
        if ($tmp) {
            @unlink($tmp);
        }
        botble04Abort('Atomic file replacement failed; use rollback backup');
    }
    echo 'Installed '.$item['path']."\n";
}

foreach ([
    ['migrate', '--force', '--no-interaction', '--path=database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php'],
    ['optimize:clear'],
] as $params) {
    $proc = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $params), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $target);
    if (! is_resource($proc)) {
        botble04Abort('Artisan unavailable; use private rollback backup');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        botble04Abort('Artisan migration/cache command failed; use private rollback backup');
    }
}
echo "BOTBLE-04 staged code installed. First-party pilot is OFF until explicitly configured; pairing NOT verified.\n";
