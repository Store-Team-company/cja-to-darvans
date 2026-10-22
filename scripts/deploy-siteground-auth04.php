<?php
/**
 * Data Pilot AUTH-04 — selective, hash-checked SiteGround updater.
 *
 * This only installs two AUTH-04 application PHP files and a CLI-only,
 * secret-safe diagnosis script. It never touches .env, DB or OTP migration.
 *
 * --check --target=/absolute/path/to/data-pilot-app
 * --apply --backup-confirmed --expected-head=REVIEWED_SHA --target=...
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function stopAuth04(string $message): never
{
    fwrite(STDERR, "AUTH-04 ABORT: {$message}\n");
    exit(2);
}

function gitAuth04(string $root, array $args): array
{
    $proc = proc_open(array_merge(['git'], $args), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);

    if (! is_resource($proc)) {
        stopAuth04('Git is unavailable.');
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out];
}

$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($check === $apply) {
    stopAuth04('Specify exactly one of --check or --apply.');
}

$getOption = static function (string $name) use ($argv): string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name.'=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return '';
};

$source = realpath(dirname(__DIR__));
$target = realpath($getOption('--target'));
if (! $source || ! $target || $source === $target
    || basename($target) !== 'data-pilot-app'
    || ! is_file($target.'/.siteground-layout')
    || ! is_file($target.'/.env')
    || ! is_file($target.'/artisan')
    || ! is_file($target.'/vendor/autoload.php')
    || ! is_dir(dirname($target).'/public_html')) {
    stopAuth04('Target must be the existing private SiteGround Data Pilot installation.');
}
$branch = trim(gitAuth04($source, ['symbolic-ref', '--short', 'HEAD'])[1]);
$head = trim(gitAuth04($source, ['rev-parse', 'HEAD'])[1]);
if ($branch !== 'fix/datapilot-auth-04-otp-mail-delivery'
    || ! preg_match('/^[a-f0-9]{40}$/D', $head)
    || trim(gitAuth04($source, ['status', '--porcelain'])[1]) !== '') {
    stopAuth04('Source must be clean and on the reviewed AUTH-04 branch.');
}
$auth03 = 'd2805c2839c1f04ddb5a5cbf97ccbf335a2aa853';
if (gitAuth04($source, ['merge-base', '--is-ancestor', $auth03, 'HEAD'])[0] !== 0) {
    stopAuth04('Source is not based on the approved AUTH-03 code.');
}
if ($apply) {
    $expected = $getOption('--expected-head');
    if (! in_array('--backup-confirmed', $argv, true)
        || ! preg_match('/^[a-f0-9]{40}$/D', $expected)
        || ! hash_equals($expected, $head)) {
        stopAuth04('Apply requires --backup-confirmed and --expected-head=EXACT_REVIEWED_SHA.');
    }
}

// AUTH-03 must already exist on the PRIVATE installation.
// Not all previous SaaS stacks are present on GitHub main.
foreach ([
    'app/Models/SaasEmailOtpChallenge.php',
    'database/migrations/2026_10_22_000003_create_saas_email_otp_challenges_table.php',
    'resources/views/saas/auth/otp.blade.php',
    'resources/views/saas/emails/otp.blade.php',
] as $required) {
    if (! is_file($target.'/'.$required)) {
        stopAuth04('AUTH-03 is not fully installed; missing '.$required.'.');
    }
}

$paths = [
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'scripts/diagnose-siteground-auth04.php',
];
$updates = [];

foreach ($paths as $path) {
    if (! preg_match('/^[A-Za-z0-9_\/.\-]+$/D', $path) || str_contains($path, '..')) {
        stopAuth04('Unsafe path in file manifest.');
    }

    $from = $source.'/'.$path;
    $to = $target.'/'.$path;
    if (! is_file($from) || is_link($from) || is_link($to)) {
        stopAuth04('Missing or symlinked manifest path: '.$path);
    }
    $node = $target;
    foreach (array_slice(explode('/', $path), 0, -1) as $part) {
        $node .= '/'.$part;
        if (is_link($node)) {
            stopAuth04('Symlink target parent refused.');
        }
    }

    $current = is_file($to) ? hash_file('sha256', $to) : null;
    $next = hash_file('sha256', $from);
    if ($current === $next) {
        $action = 'unchanged';
    } elseif ($path === 'scripts/diagnose-siteground-auth04.php' && $current === null) {
        $action = 'add';
    } elseif ($current !== null && $path !== 'scripts/diagnose-siteground-auth04.php') {
        [$ok, $original] = gitAuth04($source, ['show', $auth03.':'.$path]);
        if ($ok !== 0 || ! hash_equals(hash('sha256', $original), $current)) {
            stopAuth04('Live code differs from reviewed AUTH-03: '.$path.'; refusing overwrite.');
        }
        $action = 'replace';
    } else {
        stopAuth04('Unexpected live file exists or is missing: '.$path);
    }
    $updates[] = compact('path', 'from', 'to', 'action');
}

echo "AUTH-04 dry-run summary: SOURCE {$head}\n";
foreach ($updates as $item) {
    echo $item['action'].' '.$item['path']."\n";
}
echo "No mail credentials, .env, customer DB, migration, vendor or public_html changes.\n";
if ($check) {
    echo "Read-only preflight complete. No files or DB modified.\n";
    exit(0);
}

umask(0077);
$backup = dirname($target).'/datapilot-private-backups/auth04-'
    .gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (! mkdir($backup, 0700, true)) {
    stopAuth04('Could not create private rollback directory.');
}
$added = [];
foreach ($updates as $item) {
    if ($item['action'] === 'replace') {
        $dest = $backup.'/'.$item['path'];
        if (! is_dir(dirname($dest)) && ! mkdir(dirname($dest), 0700, true)) {
            stopAuth04('Cannot make private backup subdirectory.');
        }
        if (! copy($item['to'], $dest)) {
            stopAuth04('Backup failed, nothing will be replaced.');
        }
    } elseif ($item['action'] === 'add') {
        $added[] = $item['path'];
    }
}
file_put_contents($backup.'/ADDED_FILES.txt', implode("\n", $added)."\n", LOCK_EX);
echo 'Private rollback directory: '.$backup."\n";

foreach ($updates as $item) {
    if ($item['action'] === 'unchanged') {
        continue;
    }
    if (! is_dir(dirname($item['to'])) && ! mkdir(dirname($item['to']), 0755, true)) {
        stopAuth04('Cannot make target directory.');
    }
    $tmp = tempnam(dirname($item['to']), '.auth04-');
    if (! $tmp || ! copy($item['from'], $tmp) || ! chmod($tmp, 0644)
        || ! rename($tmp, $item['to'])) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        stopAuth04('Incomplete installation; restore from private rollback backup.');
    }
    echo 'Installed '.$item['path']."\n";
}

$proc = proc_open([PHP_BINARY, $target.'/artisan', 'optimize:clear'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $target);
if (! is_resource($proc)) {
    stopAuth04('Could not clear Laravel caches. Restore from private rollback backup.');
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]);
fwrite(STDERR, stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    stopAuth04('Cache clearing failed; see private rollback directory.');
}
echo "AUTH-04 code installed, but SMTP acceptance and real inbox login remain UNVERIFIED.\n";
