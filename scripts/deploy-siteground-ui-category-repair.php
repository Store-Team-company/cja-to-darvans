<?php
/**
 * Safe, version-checked deployment of ONLY the reviewed UI/category fixes.
 *
 * Run from a CLEAN checkout of
 * feature/datapilot-ui-category-repair-20261022:
 *
 * php scripts/deploy-siteground-ui-category-repair.php --check --target=/absolute/path/data-pilot-app
 * php scripts/deploy-siteground-ui-category-repair.php --apply --backup-confirmed --target=/absolute/path/data-pilot-app
 *
 * No composer, database schema, .env, vendor, uploads or public webroot writes.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function failRepair(string $message): never
{
    fwrite(STDERR, "ABORT: ".$message."\n");
    exit(2);
}

function gitRead(string $root, array $args): string
{
    $pipes = [];
    $process = proc_open(
        array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        failRepair('Git is unavailable on SiteGround.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($output)) {
        failRepair('Git verification failed (ensure remote branches were fetched). '.$err);
    }

    return $output;
}

$action = in_array('--check', $argv, true) ? 'check' : (in_array('--apply', $argv, true) ? 'apply' : '');
if ($action === '' || (in_array('--check', $argv, true) && in_array('--apply', $argv, true))) {
    failRepair('Choose exactly --check or --apply --backup-confirmed.');
}
$targetArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $targetArg = substr($arg, strlen('--target='));
    }
}
if (!$targetArg || !str_starts_with($targetArg, '/')) {
    failRepair('Supply --target=/absolute/path/data-pilot-app.');
}
if ($action === 'apply' && !in_array('--backup-confirmed', $argv, true)) {
    failRepair('Create an independent site backup first, then confirm with --backup-confirmed.');
}

$repo = realpath(dirname(__DIR__));
$target = realpath($targetArg);
if (!$repo || !$target || $repo === $target
    || basename($target) !== 'data-pilot-app'
    || !is_dir(dirname($target).'/public_html')
    || !is_file($target.'/artisan')
    || !is_file($target.'/.siteground-layout')
    || !is_file($target.'/.env')
    || !is_file($target.'/vendor/autoload.php')) {
    failRepair('Target is not the expected installed private SiteGround Laravel folder.');
}

$branch = trim(gitRead($repo, ['symbolic-ref', '--short', 'HEAD']));
if ($branch !== 'feature/datapilot-ui-category-repair-20261022') {
    failRepair('Switch to the reviewed repair branch in your ~/dev/data-pilot-src checkout.');
}
if (trim(gitRead($repo, ['status', '--porcelain'])) !== '') {
    failRepair('Git checkout has local changes. Commit/review them before deployment.');
}
$files = [
    'app/Http/Controllers/BalencoDataPilotController.php',
    'app/Http/Controllers/BdpCategoryMappingController.php',
    'app/Services/BdpShofyCategoryService.php',
    'resources/views/balenco-data-pilot/layout.blade.php',
    'resources/views/balenco-data-pilot/dashboard.blade.php',
    'resources/views/balenco-data-pilot/sync-queue.blade.php',
    'resources/views/balenco-data-pilot/category-mapping.blade.php',
];
$approvedBases = [
    '36dc248fd17e76daca05096ed927ac51cf616e26', // SiteGround installer, before UI fix
    'a14a6772c1483989c8f90aecc1a7b268cbe28d35', // UI v3, before category fix
];

$operations = [];
foreach ($files as $rel) {
    $source = $repo.'/'.$rel;
    $destination = $target.'/'.$rel;
    if (!is_file($source) || is_link($source)
        || !is_file($destination) || is_link($destination)) {
        failRepair('A required source/target file is missing or symlinked: '.$rel);
    }
    $walk = $target;
    foreach (array_slice(explode('/', $rel), 0, -1) as $part) {
        $walk .= '/'.$part;
        if (is_link($walk)) {
            failRepair('A target parent directory is a symlink: '.$rel);
        }
    }
    $newHash = hash_file('sha256', $source);
    $currentHash = hash_file('sha256', $destination);
    $allowed = [$newHash];
    foreach ($approvedBases as $base) {
        $allowed[] = hash('sha256', gitRead($repo, ['show', $base.':'.$rel]));
    }
    if (!in_array($currentHash, $allowed, true)) {
        failRepair('Existing file differs from both supported release baselines: '.$rel
            .'. Do not overwrite local customizations; inspect it first.');
    }
    $operations[] = [
        'path' => $rel,
        'from' => $source,
        'to' => $destination,
        'status' => $currentHash === $newHash ? 'already updated' : 'update',
    ];
}

$needed = count(array_filter($operations, fn (array $op): bool => $op['status'] === 'update'));
echo 'Data Pilot UI + Category Mapping repair'."\n";
echo 'Preflight PASS: '.count($operations).' files checked, '.$needed.' files to update.'."\n";
echo 'No changes to admin authentication, SaaS registration, .env, products, database, vendors or public_html.'."\n";
foreach ($operations as $op) {
    echo '  '.$op['status'].'  '.$op['path']."\n";
}
if ($action === 'check') {
    echo "Dry run only. Nothing was changed.\n";
    exit(0);
}
if ($needed === 0) {
    echo "All repair files already installed.\n";
    exit(0);
}

umask(0077);
$backupBase = dirname($target).'/datapilot-private-backups';
$backup = $backupBase.'/ui-category-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    failRepair('Could not create private backup folder.');
}
foreach ($operations as $op) {
    if ($op['status'] !== 'update') {
        continue;
    }
    $backupFile = $backup.'/'.$op['path'];
    $folder = dirname($backupFile);
    if (!is_dir($folder) && !mkdir($folder, 0700, true)) {
        failRepair('Cannot create private backup path: '.$op['path']);
    }
    if (!copy($op['to'], $backupFile)) {
        failRepair('Backup failed. No application source files replaced.');
    }
}
echo 'Backed up original source files privately at: '.$backup."\n";

foreach ($operations as $op) {
    if ($op['status'] !== 'update') {
        continue;
    }
    $dir = dirname($op['to']);
    $tmp = tempnam($dir, '.datapilot-repair-');
    if ($tmp === false || !copy($op['from'], $tmp)
        || !chmod($tmp, 0644) || !rename($tmp, $op['to'])) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        failRepair('Write failed for '.$op['path'].'. Restore from the private backup if necessary.');
    }
    echo 'Installed '.$op['path']."\n";
}

// Only clear rendered Blade views, no application DB changes or migrations.
$command = [PHP_BINARY, $target.'/artisan', 'view:clear'];
$proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target);
if (!is_resource($proc)) {
    failRepair('Files updated, but view:clear could not start. Run php artisan view:clear manually.');
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]);
fwrite(STDERR, stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    failRepair('Files updated, but view:clear failed; inspect storage permissions.');
}
echo "SUCCESS: Only reviewed admin UI and category mapping fixes deployed.\n";
