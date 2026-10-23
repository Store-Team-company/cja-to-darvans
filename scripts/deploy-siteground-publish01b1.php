<?php

/** BOTBLE-PUBLISH-01B1 — Strict changes-only variant stage update to the signed DataPilot sender. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function publish01b1Abort(string $reason): never
{
    fwrite(STDERR, "BOTBLE-PUBLISH-01B1 ABORT: {$reason}\n");
    exit(2);
}

function publish01b1Git(string $cwd, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (!is_resource($proc)) {
        publish01b1Abort('Git unavailable');
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
    publish01b1Abort('Specify exactly one of --check or --apply');
}
if (!class_exists(\DOMDocument::class)) {
    publish01b1Abort('PHP DOM extension is required for secure rich HTML processing');
}

$source = realpath(dirname(__DIR__));
$target = realpath($get('--target'));
if (!$source || !$target || $source === $target || basename($target) !== 'data-pilot-app'
    || !is_file($target.'/.siteground-layout') || !is_file($target.'/.env')
    || !is_file($target.'/artisan') || !is_file($target.'/vendor/autoload.php')
    || !is_dir(dirname($target).'/public_html')) {
    publish01b1Abort('Unrecognized private DataPilot SiteGround installation');
}

[$branchRc, $branch] = publish01b1Git($source, ['symbolic-ref', '--short', 'HEAD']);
[$headRc, $head] = publish01b1Git($source, ['rev-parse', 'HEAD']);
[$dirtyRc, $dirty] = publish01b1Git($source, ['status', '--porcelain']);
$head = trim($head);
if ($branchRc || $headRc || $dirtyRc
    || trim($branch) !== 'feature/datapilot-botble-publish-01b1-complete-variants'
    || trim($dirty) !== '' || !preg_match('/^[0-9a-f]{40}$/D', $head)) {
    publish01b1Abort('Use clean reviewed BOTBLE-PUBLISH-01B1 branch checkout');
}

$base = '783c2a297d7f0680bbdd987aa3e047f2859e2f07';
if (publish01b1Git($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    publish01b1Abort('BOTBLE-PUBLISH-01B1 must descend from approved BOTBLE-PUBLISH-01A baseline');
}
if ($apply && (!in_array('--backup-confirmed', $argv, true)
    || !hash_equals($head, $get('--expected-head')))) {
    publish01b1Abort('Apply requires --backup-confirmed and exact --expected-head');
}

// Never overwrite other tenant, legacy admin, Stripe, vendor, Botble or environment files.
$manifest = [
    'app/Services/Saas/SaasBotbleVariantDraftPayload.php',
    'app/Services/Saas/SaasBotbleDraftDelivery.php',
    'app/Http/Controllers/Saas/SaasBotbleDraftDeliveryController.php',
    'resources/views/saas/product-edit.blade.php',
    'config/saas.php',
];

$plan = [];
foreach ($manifest as $path) {
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (!is_file($from) || is_link($from) || is_link($to)) {
        publish01b1Abort('Missing/unsafe source or symlink: '.$path);
    }
    $dir = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $dir .= '/'.$part;
        if (is_link($dir)) {
            publish01b1Abort('Symlink target parent: '.$path);
        }
    }

    $before = is_file($to) ? hash_file('sha256', $to) : null;
    $after = hash_file('sha256', $from);
    [$oldStatus, $oldContent] = publish01b1Git($source, ['show', $base.':'.$path]);
    if ($before === $after) {
        $action = 'unchanged';
    } elseif ($oldStatus === 0 && $before !== null
        && hash_equals(hash('sha256', $oldContent), $before)) {
        $action = 'replace';
    } elseif ($oldStatus !== 0 && $before === null) {
        $action = 'add';
    } else {
        publish01b1Abort('SiteGround customization in '.$path.'; refuses overwrite');
    }
    $plan[] = compact('path', 'from', 'to', 'before', 'action');
}

echo "BOTBLE-PUBLISH-01B1 source commit: {$head}\n";
foreach ($plan as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, Stripe, Botble, CJ credentials, legacy admin, existing records, or public_html file changes\n";

if ($check) {
    echo "PASS: read-only BOTBLE-PUBLISH-01B1 preflight; no changes made\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/publish01b1-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    publish01b1Abort('Unable to create private backup directory');
}
$added = [];
foreach ($plan as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
            publish01b1Abort('Unable to create backup subdirectory');
        }
        if (!copy($item['to'], $dest)) {
            publish01b1Abort('Backup failed; no files installed');
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
        publish01b1Abort('Failed to create target directory; use rollback backup');
    }
    $actual = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($actual !== $item['before']) {
        publish01b1Abort('SiteGround changed during deploy; use rollback backup');
    }
    $temp = tempnam(dirname($item['to']), '.publish01b1-');
    if (!$temp || !copy($item['from'], $temp) || !chmod($temp, 0644)
        || !rename($temp, $item['to'])) {
        if ($temp) { @unlink($temp); }
        publish01b1Abort('Atomic file update failed; use rollback backup');
    }
    echo 'Installed '.$item['path']."\n";
}

$proc = proc_open([PHP_BINARY, $target.'/artisan', 'optimize:clear'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes, $target);
if (!is_resource($proc)) {
    publish01b1Abort('Laravel cache clear unavailable; use rollback backup');
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]);
fwrite(STDERR, stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    publish01b1Abort('Laravel cache clear failed; use rollback backup');
}
echo "PASS: BOTBLE-PUBLISH-01B1 signed complete-variant staging code installed. The NEW v2 flag remains OFF until separately enabled.\n";
