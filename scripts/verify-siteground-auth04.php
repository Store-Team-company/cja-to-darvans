<?php
/** AUTH-04 offline SiteGround updater preflight smoke test; never touches live. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'd2805c2839c1f04ddb5a5cbf97ccbf335a2aa853';
$temporary = sys_get_temp_dir().'/datapilot-auth04-'.bin2hex(random_bytes(5));
$target = $temporary.'/www/example.test/data-pilot-app';

function auth04Check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('AUTH-04 updater smoke FAIL: '.$message);
    }
}
function auth04Run(array $command, string $cwd): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    auth04Check(is_resource($process), 'process start');
    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $text];
}
function auth04Write(string $path, string $contents): void
{
    auth04Check(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'mkdir');
    auth04Check(file_put_contents($path, $contents) !== false, 'write');
}

auth04Check(mkdir($target.'/vendor', 0700, true), 'mock vendor directory');
auth04Check(mkdir(dirname($target).'/public_html', 0700, true), 'mock public_html');
foreach (['.siteground-layout', '.env', 'artisan', 'vendor/autoload.php'] as $f) {
    auth04Write($target.'/'.$f, '<?php // isolated test'.PHP_EOL);
}
foreach ([
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'app/Services/Saas/SaasEmailOtpService.php',
    'app/Models/SaasEmailOtpChallenge.php',
    'database/migrations/2026_10_22_000003_create_saas_email_otp_challenges_table.php',
    'resources/views/saas/auth/otp.blade.php',
    'resources/views/saas/emails/otp.blade.php',
] as $file) {
    [$rc, $original] = auth04Run(['git', 'show', $base.':'.$file], $root);
    auth04Check($rc === 0, 'could not obtain exact AUTH-03 baseline '.$file);
    auth04Write($target.'/'.$file, $original);
}

$deployer = $root.'/scripts/deploy-siteground-auth04.php';
[$status, $text] = auth04Run([PHP_BINARY, $deployer, '--check', '--target='.$target], $root);
auth04Check($status === 0, 'read-only preflight: '.$text);
auth04Check(str_contains($text, 'replace app/Services/Saas/SaasEmailOtpService.php'), 'service replacement');
auth04Check(str_contains($text, 'replace app/Http/Controllers/Saas/SaasAuthController.php'), 'controller replacement');
auth04Check(str_contains($text, 'add scripts/diagnose-siteground-auth04.php'), 'read-only CLI add');
auth04Check(!is_dir(dirname($target).'/datapilot-private-backups'), 'read-only created a backup');

[$status, $text] = auth04Run([PHP_BINARY, $deployer,
    '--apply', '--backup-confirmed', '--target='.$target], $root);
auth04Check($status !== 0 && str_contains($text, '--expected-head'),
    'apply missing reviewed SHA unexpectedly allowed');

auth04Write($target.'/app/Services/Saas/SaasEmailOtpService.php',
    (string) file_get_contents($target.'/app/Services/Saas/SaasEmailOtpService.php')."// unknown local change\n");
[$status, $text] = auth04Run([PHP_BINARY, $deployer, '--check', '--target='.$target], $root);
auth04Check($status !== 0 && str_contains($text, 'differs from reviewed AUTH-03'),
    'unknown live customization should block deployment');

echo "PASS: AUTH-04 selective SiteGround preflight, read-only checks, exact baseline guard and review-SHA gate.\n";
