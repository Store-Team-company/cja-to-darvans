<?php

/** BOTBLE-PUBLISH-01A — Strict changes-only update to the customer CJ preview/import UI. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function publish01aAbort(string $reason): never
{
    fwrite(STDERR, "BOTBLE-PUBLISH-01A ABORT: {$reason}\n");
    exit(2);
}

function publish01aGit(string $cwd, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (!is_resource($proc)) {
        publish01aAbort('Git unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $stdout];
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
    publish01aAbort('Specify exactly one of --check or --apply');
}
if (!class_exists(\DOMDocument::class)) {
    publish01aAbort('PHP DOM extension is required for secure rich HTML processing');
}

$source = realpath(dirname(__DIR__));
$target = realpath($get('--target'));
if (!$source || !$target || $source === $target || basename($target) !== 'data-pilot-app'
    || !is_file($target.'/.siteground-layout') || !is_file($target.'/.env')
    || !is_file($target.'/artisan') || !is_file($target.'/vendor/autoload.php')
    || !is_dir(dirname($target).'/public_html')) {
    publish01aAbort('Unrecognized private DataPilot SiteGround installation');
}

[$branchRc, $branch] = publish01aGit($source, ['symbolic-ref', '--short', 'HEAD']);
[$headRc, $head] = publish01aGit($source, ['rev-parse', 'HEAD']);
[$dirtyRc, $dirty] = publish01aGit($source, ['status', '--porcelain']);
$head = trim($head);
if ($branchRc || $headRc || $dirtyRc
    || trim($branch) !== 'feature/datapilot-botble-publish-01a-signed-draft-delivery'
    || trim($dirty) !== '' || !preg_match('/^[0-9a-f]{40}$/D', $head)) {
    publish01aAbort('Use clean reviewed BOTBLE-PUBLISH-01A branch checkout');
}

$base = '2d0bb2038377ec4933bd0d62f3c93a549e24e621';
if (publish01aGit($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    publish01aAbort('BOTBLE-PUBLISH-01A must descend from reviewed BILLING-03 baseline');
}
if ($apply && (!in_array('--backup-confirmed', $argv, true)
    || !hash_equals($head, $get('--expected-head')))) {
    publish01aAbort('Apply requires --backup-confirmed and exact --expected-head');
}

// Never overwrite other tenant, legacy admin, Stripe, vendor, Botble or environment files.
$manifest = [
    'app/Models/SaasBotbleProductDelivery.php',
    'app/Services/Saas/SaasBotbleDeliveryException.php',
    'app/Services/Saas/SaasBotbleDraftDelivery.php',
    'app/Http/Controllers/Saas/SaasBotbleDraftDeliveryController.php',
    'resources/views/saas/product-edit.blade.php',
    'routes/saas.php',
    'config/saas.php',
    'database/migrations/2026_10_23_000012_create_saas_botble_product_deliveries.php',
];

$plan = [];
foreach ($manifest as $path) {
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (!is_file($from) || is_link($from) || is_link($to)) {
        publish01aAbort('Missing/unsafe source or symlink: '.$path);
    }
    $dir = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $dir .= '/'.$part;
        if (is_link($dir)) {
            publish01aAbort('Symlink target parent: '.$path);
        }
    }

    $before = is_file($to) ? hash_file('sha256', $to) : null;
    $after = hash_file('sha256', $from);
    [$oldStatus, $oldContent] = publish01aGit($source, ['show', $base.':'.$path]);
    if ($before === $after) {
        $action = 'unchanged';
    } elseif ($oldStatus === 0 && $before !== null
        && hash_equals(hash('sha256', $oldContent), $before)) {
        $action = 'replace';
    } elseif ($oldStatus !== 0 && $before === null) {
        $action = 'add';
    } else {
        publish01aAbort('SiteGround customization in '.$path.'; refuses overwrite');
    }
    $plan[] = compact('path', 'from', 'to', 'before', 'action');
}

echo "BOTBLE-PUBLISH-01A source commit: {$head}\n";
foreach ($plan as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, Stripe, Botble, CJ credentials, legacy admin, existing records, or public_html file changes\n";

if ($check) {
    echo "PASS: read-only BOTBLE-PUBLISH-01A preflight; no changes made\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/publish01a-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    publish01aAbort('Unable to create private backup directory');
}
$added = [];
foreach ($plan as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
            publish01aAbort('Unable to create backup subdirectory');
        }
        if (!copy($item['to'], $dest)) {
            publish01aAbort('Backup failed; no files installed');
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
    if (!is_dir(dirname($item['to'])) && !mkdir(dirname($item['to']), 0755, true)) {
        publish01aAbort('Failed to create target directory; use rollback backup');
    }
    $actual = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($actual !== $item['before']) {
        publish01aAbort('SiteGround changed during deploy; use rollback backup');
    }
    $temp = tempnam(dirname($item['to']), '.publish01a-');
    if (!$temp || !copy($item['from'], $temp) || !chmod($temp, 0644)
        || !rename($temp, $item['to'])) {
        if ($temp) { @unlink($temp); }
        publish01aAbort('Atomic file update failed; use rollback backup');
    }
    echo 'Installed '.$item['path']."\n";
}

$proc = proc_open([PHP_BINARY, $target.'/artisan', 'optimize:clear'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes, $target);
if (!is_resource($proc)) {
    publish01aAbort('Laravel cache clear unavailable; use rollback backup');
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]);
fwrite(STDERR, stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    publish01aAbort('Laravel cache clear failed; use rollback backup');
}
$migration = proc_open([
    PHP_BINARY, $target.'/artisan', 'migrate', '--force', '--no-interaction',
    '--path=database/migrations/2026_10_23_000012_create_saas_botble_product_deliveries.php',
], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $mpipes, $target);
if (!is_resource($migration)) {
    publish01aAbort('Migration process unavailable; use rollback backup');
}
fclose($mpipes[0]);
echo stream_get_contents($mpipes[1]);
fwrite(STDERR, stream_get_contents($mpipes[2]));
fclose($mpipes[1]);
fclose($mpipes[2]);
if (proc_close($migration) !== 0) {
    publish01aAbort('Additive product editing migration failed; use rollback backup');
}
echo "PASS: BOTBLE-PUBLISH-01A signed stage-only sandbox review inbox installed. Feature remains OFF.\n";
