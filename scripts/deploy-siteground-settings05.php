<?php
/**
 * Data Pilot SETTINGS-05 safe changes-only SiteGround updater.
 * --check (read-only); --apply --accept-disposable-installation
 * A private backup of replaced PHP/Blade files is created during apply.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
function abortSettings05(string $message): never
{
    fwrite(STDERR, "ABORT: {$message}\n");
    exit(2);
}
function gitSettings05(string $root, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        abortSettings05('Git could not be launched.');
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
function requiredGitSettings05(string $root, array $args): string
{
    [$code, $out] = gitSettings05($root, $args);
    if ($code !== 0) {
        abortSettings05('Git source version could not be verified.');
    }
    return $out;
}

$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
$accepted = in_array('--accept-disposable-installation', $argv, true)
    || in_array('--backup-confirmed', $argv, true);
if ($check === $apply || ($apply && !$accepted)) {
    abortSettings05('Use --check or --apply --accept-disposable-installation (or --backup-confirmed).');
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
    abortSettings05('Target is not a verified existing private Data Pilot installation.');
}
$branch = trim(requiredGitSettings05($root, ['symbolic-ref', '--short', 'HEAD']));
if ($branch !== 'feature/datapilot-settings-05-store-save-test-repair'
    || trim(requiredGitSettings05($root, ['status', '--porcelain'])) !== '') {
    abortSettings05('Source must be a clean checkout of the reviewed SETTINGS-05 branch.');
}
$base = '52938ab67de5723e7584d894c7c16bb9ba6012fe';
$files = [
    'app/Http/Controllers/BdpSettingsController.php',
    'resources/views/balenco-data-pilot/settings.blade.php',
];

$updates = [];
foreach ($files as $path) {
    if (str_contains($path, '..') || !preg_match('/^[A-Za-z0-9_\/.\-]+$/D', $path)) {
        abortSettings05('Unsafe file manifest.');
    }
    $from = $root.'/'.$path;
    $to = $target.'/'.$path;
    if (!is_file($from) || is_link($from) || is_link($to)) {
        abortSettings05('Missing or symlinked file: '.$path);
    }
    $node = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $node .= '/'.$part;
        if (is_link($node)) {
            abortSettings05('Symlink parent refused: '.$path);
        }
    }
    $current = is_file($to) ? hash_file('sha256', $to) : null;
    $next = hash_file('sha256', $from);
    [$exists] = gitSettings05($root, ['cat-file', '-e', $base.':'.$path]);
    if ($current === $next) {
        $action = 'unchanged';
    } elseif ($exists !== 0 && $current === null) {
        $action = 'add';
    } elseif ($exists === 0 && $current !== null
        && hash('sha256', requiredGitSettings05($root, ['show', $base.':'.$path])) === $current) {
        $action = 'replace';
    } else {
        abortSettings05('Live file differs from approved base: '.$path.'. Do not force.');
    }
    $updates[] = compact('path', 'from', 'to', 'action');
}

$changed = count(array_filter($updates, fn (array $u) => $u['action'] !== 'unchanged'));
echo "Data Pilot SETTINGS-05 connected-store repair\n";
echo 'Preflight PASS: '.count($updates).' files checked; '.$changed." files to update.\n";
echo "No database changes; existing products, credentials, .env, storage, vendor and public_html are untouched.\n";
if ($check) {
    echo "Dry-run only; no files or database records changed.\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/settings05-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    abortSettings05('Could not create private source backup directory.');
}
$added = [];
foreach ($updates as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
            abortSettings05('Could not make private backup subfolder.');
        }
        if (!copy($item['to'], $dest)) {
            abortSettings05('Source backup failed, refusing deployment.');
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
        abortSettings05('Unable to create directory for '.$item['path']);
    }
    $tmp = tempnam(dirname($item['to']), '.settings05-');
    if (!$tmp || !copy($item['from'], $tmp) || !chmod($tmp, 0644)
        || !rename($tmp, $item['to'])) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        abortSettings05('Failed installing '.$item['path'].'; review private backup.');
    }
    echo 'Installed '.$item['path']."\n";
}

function artisanSettings05(string $target, array $args): void
{
    $proc = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target);
    if (!is_resource($proc)) {
        abortSettings05('Could not run artisan.');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        abortSettings05('Artisan failed; keep registration disabled and inspect server logs.');
    }
}
artisanSettings05($target, ['optimize:clear']);
echo "SUCCESS: SETTINGS-05 connected-store save/test repair installed. No database migration, product changes or .env edits.\n";
