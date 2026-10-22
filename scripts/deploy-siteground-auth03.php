<?php
/**
 * Data Pilot AUTH-03 safe changes-only SiteGround updater.
 * --check (read-only); --apply --accept-disposable-installation
 * A private backup of replaced PHP/Blade files is created during apply.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
function abortAuth03(string $message): never
{
    fwrite(STDERR, "ABORT: {$message}\n");
    exit(2);
}
function gitAuth03(string $root, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        abortAuth03('Git could not be launched.');
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
function requiredGitAuth03(string $root, array $args): string
{
    [$code, $out] = gitAuth03($root, $args);
    if ($code !== 0) {
        abortAuth03('Git source version could not be verified.');
    }
    return $out;
}

$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
$accepted = in_array('--accept-disposable-installation', $argv, true)
    || in_array('--backup-confirmed', $argv, true);
if ($check === $apply || ($apply && !$accepted)) {
    abortAuth03('Use --check or --apply --accept-disposable-installation (or --backup-confirmed).');
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
    abortAuth03('Target is not a verified existing private Data Pilot installation.');
}
$branch = trim(requiredGitAuth03($root, ['symbolic-ref', '--short', 'HEAD']));
if ($branch !== 'feature/datapilot-auth-03-email-otp-optional-password'
    || trim(requiredGitAuth03($root, ['status', '--porcelain'])) !== '') {
    abortAuth03('Source must be a clean checkout of the reviewed AUTH-03 branch.');
}
$base = '968311ef6d9425cf6fc03155280a80e636ff2312';
$files = [
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'routes/saas.php',
    'resources/views/saas/auth/register.blade.php',
    'resources/views/saas/auth/login.blade.php',
    'resources/views/saas/auth/otp.blade.php',
    'resources/views/saas/emails/otp.blade.php',
    'app/Mail/SaasEmailOtpMail.php',
    'app/Models/SaasEmailOtpChallenge.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'database/migrations/2026_10_22_000003_create_saas_email_otp_challenges_table.php',
];

$updates = [];
foreach ($files as $path) {
    if (str_contains($path, '..') || !preg_match('/^[A-Za-z0-9_\/.\-]+$/D', $path)) {
        abortAuth03('Unsafe file manifest.');
    }
    $from = $root.'/'.$path;
    $to = $target.'/'.$path;
    if (!is_file($from) || is_link($from) || is_link($to)) {
        abortAuth03('Missing or symlinked file: '.$path);
    }
    $node = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $node .= '/'.$part;
        if (is_link($node)) {
            abortAuth03('Symlink parent refused: '.$path);
        }
    }
    $current = is_file($to) ? hash_file('sha256', $to) : null;
    $next = hash_file('sha256', $from);
    [$exists] = gitAuth03($root, ['cat-file', '-e', $base.':'.$path]);
    if ($current === $next) {
        $action = 'unchanged';
    } elseif ($exists !== 0 && $current === null) {
        $action = 'add';
    } elseif ($exists === 0 && $current !== null
        && hash('sha256', requiredGitAuth03($root, ['show', $base.':'.$path])) === $current) {
        $action = 'replace';
    } else {
        abortAuth03('Live file differs from approved base: '.$path.'. Do not force.');
    }
    $updates[] = compact('path', 'from', 'to', 'action');
}

$changed = count(array_filter($updates, fn (array $u) => $u['action'] !== 'unchanged'));
echo "Data Pilot AUTH-03 email OTP update\n";
echo 'Preflight PASS: '.count($updates).' files checked; '.$changed." files to update.\n";
echo "No existing product, credential, .env, storage, vendor or public_html changes.\n";
if ($check) {
    echo "Dry-run only; no files or database records changed.\n";
    exit(0);
}

$env = (string) file_get_contents($target.'/.env');
if (preg_match('/^\s*SAAS_REGISTRATION_ENABLED\s*=\s*(true|1|yes)\s*$/im', $env)) {
    abortAuth03('Disable SAAS_REGISTRATION_ENABLED before changing public authentication code.');
}
umask(0077);
$backup = dirname($target).'/datapilot-private-backups/auth03-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700, true)) {
    abortAuth03('Could not create private source backup directory.');
}
$added = [];
foreach ($updates as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
            abortAuth03('Could not make private backup subfolder.');
        }
        if (!copy($item['to'], $dest)) {
            abortAuth03('Source backup failed, refusing deployment.');
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
        abortAuth03('Unable to create directory for '.$item['path']);
    }
    $tmp = tempnam(dirname($item['to']), '.auth03-');
    if (!$tmp || !copy($item['from'], $tmp) || !chmod($tmp, 0644)
        || !rename($tmp, $item['to'])) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        abortAuth03('Failed installing '.$item['path'].'; review private backup.');
    }
    echo 'Installed '.$item['path']."\n";
}

function artisanAuth03(string $target, array $args): void
{
    $proc = proc_open(array_merge([PHP_BINARY, $target.'/artisan'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target);
    if (!is_resource($proc)) {
        abortAuth03('Could not run artisan.');
    }
    fclose($pipes[0]);
    echo stream_get_contents($pipes[1]);
    fwrite(STDERR, stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        abortAuth03('Artisan failed; keep registration disabled and inspect server logs.');
    }
}
artisanAuth03($target, [
    'migrate', '--force', '--no-interaction',
    '--path=database/migrations/2026_10_22_000003_create_saas_email_otp_challenges_table.php',
]);
artisanAuth03($target, ['optimize:clear']);
echo "SUCCESS: AUTH-03 installed. Public registration remains disabled until SMTP QA.\n";
