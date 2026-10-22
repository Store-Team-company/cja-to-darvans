<?php
/**
 * Data Pilot BOTBLE-03A safe changes-only SiteGround updater.
 * --check (read-only); --apply --accept-disposable-installation
 * A private backup of replaced PHP/Blade files is created during apply.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
function abortBotble03A(string $message): never
{
    fwrite(STDERR, "ABORT: {$message}\n");
    exit(2);
}
function gitBotble03A(string $root, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        abortBotble03A('Git could not be launched.');
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
function requiredGitBotble03A(string $root, array $args): string
{
    [$code, $out] = gitBotble03A($root, $args);
    if ($code !== 0) {
        abortBotble03A('Git source version could not be verified.');
    }
    return $out;
}

$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
$accepted = in_array('--accept-disposable-installation', $argv, true)
    || in_array('--backup-confirmed', $argv, true);
if ($check === $apply || ($apply && !$accepted)) {
    abortBotble03A('Use --check or --apply --accept-disposable-installation (or --backup-confirmed).');
}
$root = realpath(dirname(__DIR__));
$targetArg = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $targetArg = substr($arg, 9);
    }
}
$target = $targetArg !== '' ? realpath($targetArg) : false;
if (!$root || !$target || $target === $root || basename($target) !== 'data-pilot-app'
    || !is_file($target.'/.siteground-layout') || !is_file($target.'/.env')
    || !is_file($target.'/artisan') || !is_file($target.'/vendor/autoload.php')
    || !is_dir(dirname($target).'/public_html')) {
    abortBotble03A('Target is not a verified existing private Data Pilot installation.');
}
$branch = trim(requiredGitBotble03A($root, ['symbolic-ref', '--short', 'HEAD']));
if ($branch !== 'feature/datapilot-botble-connector-03a-pairing-inbox'
    || trim(requiredGitBotble03A($root, ['status', '--porcelain'])) !== '') {
    abortBotble03A('Source must be a clean checkout of the reviewed BOTBLE-03A branch.');
}
$base = 'd2805c2839c1f04ddb5a5cbf97ccbf335a2aa853';
$files = [
    'app/Http/Controllers/Saas/SaasBotblePairingController.php',
    'routes/saas.php',
    'routes/api.php',
    'resources/views/saas/connections.blade.php',
    'database/migrations/2026_10_22_000004_create_saas_botble_pairing_challenges.php',
];

$updates = [];
foreach ($files as $path) {
    if (str_contains($path, '..') || !preg_match('/^[A-Za-z0-9_\/.\-]+$/D', $path)) {
        abortBotble03A('Unsafe file manifest.');
    }
    $from = $root.'/'.$path;
    $to = $target.'/'.$path;
    if (!is_file($from) || is_link($from) || is_link($to)) {
        abortBotble03A('Missing or symlinked file: '.$path);
    }
    $node = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $node .= '/'.$part;
        if (is_link($node)) {
            abortBotble03A('Symlink parent refused: '.$path);
        }
    }
    $current = is_file($to) ? hash_file('sha256', $to) : null;
    $next = hash_file('sha256', $from);
    [$exists] = gitBotble03A($root, ['cat-file', '-e', $base.':'.$path]);
    if ($current === $next) {
        $action = 'unchanged';
    } elseif ($exists !== 0 && $current === null) {
        $action = 'add';
    } elseif ($exists === 0 && $current !== null
        && hash('sha256', requiredGitBotble03A($root, ['show', $base.':'.$path])) === $current) {
        $action = 'replace';
    } else {
        abortBotble03A('Live file differs from approved base: '.$path.'. Do not force.');
    }
    $updates[] = compact('path', 'from', 'to', 'action');
}

$changed = count(array_filter($updates, fn (array $u) => $u['action'] !== 'unchanged'));
echo "Data Pilot BOTBLE-03A Botble pairing update\n";
echo 'Preflight PASS: '.count($updates).' files checked; '.$changed." files to update.\n";
echo "No existing product, credential, .env, storage, vendor or public_html changes.\n";
if ($check) {
    echo "Dry-run only; no files or database records changed.\n";
    exit(0);
}

$env = (string) file_get_contents($target.'/.env');
if (preg_match('/^\s*SAAS_REGISTRATION_ENABLED\s*=\s*(true|1|yes)\s*$/im', $env)) {
    abortBotble03A('Disable SAAS_REGISTRATION_ENABLED before updating Botble pairing routes.');
}
umask(0077);
$backup = dirname($target).'/datapilot-private-backups/botble03a-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    abortBotble03A('Could not create private source backup directory.');
}
$added = [];
foreach ($updates as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
            abortBotble03A('Could not make private backup subfolder.');
        }
        if (!copy($item['to'], $dest)) {
            abortBotble03A('Source backup failed, refusing deployment.');
        }
    } elseif ($item['action'] === 'add') {
        $added[] = $item['path'];
    }
}
file_put_contents($backup.'/ADDED_FILES.txt', implode("\n", $added)."\n", LOCK_EX);
echo 'Private source rollback: '.$backup."\n";

foreach ($updates as $item) {
    if ($item['action'] === 'unchanged') {
        continue;
    }
    if (!is_dir(dirname($item['to'])) && !mkdir(dirname($item['to']), 0755, true)) {
        abortBotble03A('Unable to create directory for '.$item['path']);
    }
    $tmp = tempnam(dirname($item['to']), '.botble03a-');
    if (!$tmp || !copy($item['from'], $tmp) || !chmod($tmp, 0644)
        || !rename($tmp, $item['to'])) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        abortBotble03A('Failed installing '.$item['path'].'; review private backup.');
    }
    echo 'Installed '.$item['path']."\n";
}

function artisanBotble03A(string $target, array $args): void
{
    $proc = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target);
    if (!is_resource($proc)) {
        abortBotble03A('Could not run artisan.');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        abortBotble03A('Artisan failed; keep registration disabled and inspect server logs.');
    }
}
artisanBotble03A($target, [
    'migrate', '--force', '--no-interaction',
    '--path=database/migrations/2026_10_22_000004_create_saas_botble_pairing_challenges.php',
]);
artisanBotble03A($target, ['optimize:clear']);
echo "SUCCESS: BOTBLE-03A installed. Pairing endpoint requires a verified active Stripe subscription; no eCommerce writes are enabled.\n";
