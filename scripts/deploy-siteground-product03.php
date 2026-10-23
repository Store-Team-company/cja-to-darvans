<?php

/** PRODUCT-03 — Strict changes-only update to the customer CJ preview/import UI. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function product03Abort(string $reason): never
{
    fwrite(STDERR, "PRODUCT-03 ABORT: {$reason}\n");
    exit(2);
}

function product03Git(string $cwd, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (!is_resource($proc)) {
        product03Abort('Git unavailable');
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
    product03Abort('Specify exactly one of --check or --apply');
}

$source = realpath(dirname(__DIR__));
$target = realpath($get('--target'));
if (!$source || !$target || $source === $target || basename($target) !== 'data-pilot-app'
    || !is_file($target.'/.siteground-layout') || !is_file($target.'/.env')
    || !is_file($target.'/artisan') || !is_file($target.'/vendor/autoload.php')
    || !is_dir(dirname($target).'/public_html')) {
    product03Abort('Unrecognized private DataPilot SiteGround installation');
}

[$branchRc, $branch] = product03Git($source, ['symbolic-ref', '--short', 'HEAD']);
[$headRc, $head] = product03Git($source, ['rev-parse', 'HEAD']);
[$dirtyRc, $dirty] = product03Git($source, ['status', '--porcelain']);
$head = trim($head);
if ($branchRc || $headRc || $dirtyRc
    || trim($branch) !== 'feature/datapilot-product-03-customer-catalog'
    || trim($dirty) !== '' || !preg_match('/^[0-9a-f]{40}$/D', $head)) {
    product03Abort('Use clean reviewed PRODUCT-03 branch checkout');
}

$base = '5c62886dcfcfc91dbe9066d36d536aef4e4e19d8';
if (product03Git($source, ['merge-base', '--is-ancestor', $base, 'HEAD'])[0] !== 0) {
    product03Abort('PRODUCT-03 must descend from reviewed BILLING-03 baseline');
}
if ($apply && (!in_array('--backup-confirmed', $argv, true)
    || !hash_equals($head, $get('--expected-head')))) {
    product03Abort('Apply requires --backup-confirmed and exact --expected-head');
}

// Never overwrite other tenant, legacy admin, Stripe, vendor, Botble or environment files.
$manifest = [
    'app/Http/Controllers/Saas/SaasPortalController.php',
    'app/Http/Controllers/Saas/SaasCjProductController.php',
    'app/Http/Controllers/Saas/SaasProductEditorController.php',
    'app/Services/Saas/SaasCjCatalogBrowser.php',
    'app/Models/SaasProduct.php',
    'resources/views/saas/products.blade.php',
    'resources/views/saas/cj-import.blade.php',
    'resources/views/saas/product-edit.blade.php',
    'resources/views/saas/layout.blade.php',
    'routes/saas.php',
    'database/migrations/2026_10_23_000010_add_saas_product_merchant_edits.php',
];

$plan = [];
foreach ($manifest as $path) {
    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (!is_file($from) || is_link($from) || is_link($to)) {
        product03Abort('Missing/unsafe source or symlink: '.$path);
    }
    $dir = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $dir .= '/'.$part;
        if (is_link($dir)) {
            product03Abort('Symlink target parent: '.$path);
        }
    }

    $before = is_file($to) ? hash_file('sha256', $to) : null;
    $after = hash_file('sha256', $from);
    [$oldStatus, $oldContent] = product03Git($source, ['show', $base.':'.$path]);
    if ($before === $after) {
        $action = 'unchanged';
    } elseif ($oldStatus === 0 && $before !== null
        && hash_equals(hash('sha256', $oldContent), $before)) {
        $action = 'replace';
    } elseif ($oldStatus !== 0 && $before === null) {
        $action = 'add';
    } else {
        product03Abort('SiteGround customization in '.$path.'; refuses overwrite');
    }
    $plan[] = compact('path', 'from', 'to', 'before', 'action');
}

echo "PRODUCT-03 source commit: {$head}\n";
foreach ($plan as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No .env, Stripe, Botble, CJ credentials, legacy admin, existing records, or public_html file changes\n";

if ($check) {
    echo "PASS: read-only PRODUCT-03 preflight; no changes made\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/product03-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    product03Abort('Unable to create private backup directory');
}
$added = [];
foreach ($plan as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
            product03Abort('Unable to create backup subdirectory');
        }
        if (!copy($item['to'], $dest)) {
            product03Abort('Backup failed; no files installed');
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
        product03Abort('Failed to create target directory; use rollback backup');
    }
    $actual = is_file($item['to']) ? hash_file('sha256', $item['to']) : null;
    if ($actual !== $item['before']) {
        product03Abort('SiteGround changed during deploy; use rollback backup');
    }
    $temp = tempnam(dirname($item['to']), '.product03-');
    if (!$temp || !copy($item['from'], $temp) || !chmod($temp, 0644)
        || !rename($temp, $item['to'])) {
        if ($temp) { @unlink($temp); }
        product03Abort('Atomic file update failed; use rollback backup');
    }
    echo 'Installed '.$item['path']."\n";
}

$proc = proc_open([PHP_BINARY, $target.'/artisan', 'optimize:clear'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes, $target);
if (!is_resource($proc)) {
    product03Abort('Laravel cache clear unavailable; use rollback backup');
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]);
fwrite(STDERR, stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    product03Abort('Laravel cache clear failed; use rollback backup');
}
$migration = proc_open([
    PHP_BINARY, $target.'/artisan', 'migrate', '--force', '--no-interaction',
    '--path=database/migrations/2026_10_23_000010_add_saas_product_merchant_edits.php',
], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $mpipes, $target);
if (!is_resource($migration)) {
    product03Abort('Migration process unavailable; use rollback backup');
}
fclose($mpipes[0]);
echo stream_get_contents($mpipes[1]);
fwrite(STDERR, stream_get_contents($mpipes[2]));
fclose($mpipes[1]);
fclose($mpipes[2]);
if (proc_close($migration) !== 0) {
    product03Abort('Additive product editing migration failed; use rollback backup');
}
echo "PASS: PRODUCT-03 customer catalog + separate CJ import installed.\n";
