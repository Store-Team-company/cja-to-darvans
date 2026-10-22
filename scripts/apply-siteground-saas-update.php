<?php
/**
 * Data Pilot SiteGround incremental update - CLI ONLY.
 *
 * Place this file at the same level as data-pilot-app and public_html by
 * extracting the changes-only archive there.
 *
 *   php APPLY-SaaS-UPDATE.php --check
 *   php APPLY-SaaS-UPDATE.php --apply --db-backed-up
 *
 * The update has no public signup or Stripe billing enabled by default.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function stopUpdate(string $message): never
{
    fwrite(STDERR, 'ABORT: '.$message."\n");
    exit(2);
}
function ensureSafeRelativePath(string $path): void
{
    if (!preg_match('/^[A-Za-z0-9_.\/-]+$/D', $path)
        || str_starts_with($path, '/')
        || str_contains($path, '..')
        || str_contains($path, '//')
        || str_starts_with($path, 'public_html/')
        || str_starts_with($path, 'storage/')
        || str_starts_with($path, 'vendor/')) {
        stopUpdate('Unsafe package path: '.$path);
    }
}

$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);
$apply = in_array('--apply', $args, true);
if ($checkOnly === $apply) {
    stopUpdate('Select exactly one: --check OR --apply --db-backed-up');
}
if ($apply && !in_array('--db-backed-up', $args, true)) {
    stopUpdate('Back up your MySQL database first, then add --db-backed-up.');
}

$root = __DIR__;
$app = $root.'/data-pilot-app';
$payload = $root.'/payload/data-pilot-app';
$manifestFile = $root.'/update-manifest.json';

if (!is_file($app.'/artisan') || !is_file($app.'/.env')
    || !is_file($app.'/vendor/autoload.php') || !is_dir($root.'/public_html')) {
    stopUpdate('Missing installed split-layout application (.env, artisan, vendor, public_html). Do not install over an unrecognized directory.');
}
if (!is_file($manifestFile) || !is_dir($payload)) {
    stopUpdate('Update archive is not extracted correctly.');
}
$manifest = json_decode((string) file_get_contents($manifestFile), true);
if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
    || !is_array($manifest['files'] ?? null)
    || ($manifest['saas_disabled_by_default'] ?? false) !== true) {
    stopUpdate('Update manifest is invalid.');
}

$operations = [];
foreach ($manifest['files'] as $entry) {
    $rel = (string) ($entry['path'] ?? '');
    ensureSafeRelativePath($rel);
    if (!in_array($entry['mode'] ?? '', ['add', 'replace'], true)) {
        stopUpdate('Invalid operation for '.$rel);
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? ''))) {
        stopUpdate('Invalid checksum for '.$rel);
    }

    $target = $app.'/'.$rel;
    $source = $payload.'/'.$rel;
    if (!is_file($source) || is_link($source)
        || !hash_equals((string) $entry['sha256'], hash_file('sha256', $source))) {
        stopUpdate('Package payload is missing or corrupted: '.$rel);
    }

    // Do not follow symlinks in an existing installation, including parents.
    $relativePieces = explode('/', $rel);
    $parent = $app;
    foreach (array_slice($relativePieces, 0, -1) as $part) {
        $parent .= '/'.$part;
        if (is_link($parent)) {
            stopUpdate('Refusing symlinked application directory: '.$rel);
        }
    }
    if (is_link($target)) {
        stopUpdate('Refusing symlinked target: '.$rel);
    }
    $current = is_file($target) ? hash_file('sha256', $target) : null;
    $newHash = (string) $entry['sha256'];
    if ($current !== null && hash_equals($current, $newHash)) {
        $status = 'unchanged';
    } elseif ($entry['mode'] === 'add') {
        if ($current !== null) {
            stopUpdate('The new SaaS file already exists with different contents: '.$rel);
        }
        $status = 'new';
    } else {
        $expected = (string) ($entry['original_sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || $current === null
            || !hash_equals($expected, $current)) {
            stopUpdate('Existing file is customized or from another application version: '.$rel
                .'. DO NOT FORCE overwrite. Compare GitHub base before updating.');
        }
        $status = 'replace';
    }
    $operations[] = ['path' => $rel, 'status' => $status, 'source' => $source, 'target' => $target];
}

$changes = count(array_filter($operations, fn (array $op) => $op['status'] !== 'unchanged'));
echo "Data Pilot incremental SaaS foundation update\n";
echo "Preflight PASS: ".count($operations)." verified files, {$changes} files requiring changes.\n";
echo "Existing .env, products, uploads, vendor and public_html will NOT be modified.\n";
echo "Only new saas_* tables will be migrated. SaaS registration remains OFF.\n";
if ($checkOnly) {
    echo "No files or database records changed (dry-run).\n";
    exit(0);
}

umask(0077);
$backup = $root.'/datapilot-private-backups/saas-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!is_dir($backup) && !mkdir($backup, 0700, true)) {
    stopUpdate('Cannot create private source backup directory.');
}
$created = [];
foreach ($operations as $op) {
    if ($op['status'] === 'unchanged') {
        continue;
    }
    $dir = dirname($backup.'/'.$op['path']);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        stopUpdate('Cannot prepare private backup directory: '.$op['path']);
    }
    if (is_file($op['target']) && !copy($op['target'], $backup.'/'.$op['path'])) {
        stopUpdate('Cannot back up original source file: '.$op['path']);
    }
    if (!is_file($op['target'])) {
        $created[] = $op['path'];
    }
}
file_put_contents($backup.'/NEW_FILES.txt', implode("\n", $created)."\n", LOCK_EX);
echo "Original code backup: {$backup}\n";

foreach ($operations as $op) {
    if ($op['status'] === 'unchanged') {
        continue;
    }
    $dir = dirname($op['target']);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        stopUpdate('Cannot create application directory: '.$op['path']);
    }
    $temp = tempnam($dir, '.datapilot-update-');
    if (!$temp || !copy($op['source'], $temp) || !chmod($temp, 0644)
        || !rename($temp, $op['target'])) {
        if (is_string($temp)) {
            @unlink($temp);
        }
        stopUpdate('Write failed on '.$op['path'].'. Restore from private backup if necessary.');
    }
    echo 'Updated '.$op['path']."\n";
}

if (!chdir($app)) {
    stopUpdate('Unable to enter application folder for migration.');
}

function runLaravelCommand(string $app, string $artisanArgs): void
{
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/artisan').' '.$artisanArgs;
    passthru($cmd, $status);
    if ($status !== 0) {
        stopUpdate('Artisan command failed: '.$artisanArgs.'. Do not enable SaaS until this is resolved.');
    }
}

runLaravelCommand($app, 'config:clear');
runLaravelCommand($app, 'migrate --force --no-interaction');
runLaravelCommand($app, 'optimize:clear');
echo "SUCCESS: Incremental SaaS foundation installed; live CJ importer/data preserved.\n";
echo "SaaS registration remains disabled. Enable only on a separate QA-approved staging site.\n";
echo "Delete APPLY-SaaS-UPDATE.php, update-manifest.json and payload/ after verifying the update.\n";
