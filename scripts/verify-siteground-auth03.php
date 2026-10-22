<?php
/**
 * Test the AUTH-03 SiteGround deployment preflight against a simulated
 * previous SaaS-02 installation. No real .env, database, or site is touched.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '968311ef6d9425cf6fc03155280a80e636ff2312';
$site = sys_get_temp_dir().'/datapilot-auth03-'.bin2hex(random_bytes(5));
$target = $site.'/www/preview.test/data-pilot-app';

function requireAuth03(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('AUTH-03 smoke FAIL: '.$message);
    }
}
function gitAuth03Test(string $root, array $args): array
{
    $p = proc_open(array_merge(['git'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireAuth03(is_resource($p), 'Git execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}
function runAuth03Test(string $root, array $args): array
{
    $p = proc_open(array_merge([PHP_BINARY, $root.'/scripts/deploy-siteground-auth03.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    requireAuth03(is_resource($p), 'Updater execution failed');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p), $out];
}

requireAuth03(mkdir($target.'/vendor', 0700, true), 'temporary vendor dir');
requireAuth03(mkdir(dirname($target).'/public_html', 0700, true), 'fake public_html');
file_put_contents($target.'/.siteground-layout', 'test');
file_put_contents($target.'/.env', "SAAS_ENABLED=true\nSAAS_REGISTRATION_ENABLED=false\nSAAS_BILLING_ENABLED=false\n");
file_put_contents($target.'/artisan', "<?php // fake\n");
file_put_contents($target.'/vendor/autoload.php', "<?php // fake\n");

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
$existing = [];
foreach ($files as $path) {
    [$rc] = gitAuth03Test($root, ['cat-file', '-e', $base.':'.$path]);
    if ($rc !== 0) {
        continue;
    }
    [$rc, $source] = gitAuth03Test($root, ['show', $base.':'.$path]);
    requireAuth03($rc === 0, 'could not inspect base file');
    $dest = $target.'/'.$path;
    requireAuth03(is_dir(dirname($dest)) || mkdir(dirname($dest), 0700, true), 'making app directory');
    file_put_contents($dest, $source);
    $existing[] = $dest;
}

[$rc, $output] = runAuth03Test($root, ['--check', '--target='.$target]);
requireAuth03($rc === 0 && str_contains($output, 'Preflight PASS: 10 files checked; 10 files to update'),
    'baseline compatibility: '.$output);
requireAuth03(!is_dir(dirname($target).'/datapilot-private-backups'), 'dry-run wrote backup');
requireAuth03(!file_exists($target.'/app/Mail/SaasEmailOtpMail.php'), 'dry-run wrote mailer');

[$rc, $output] = runAuth03Test($root, ['--apply', '--target='.$target]);
requireAuth03($rc !== 0 && str_contains($output, '--accept-disposable-installation'),
    'apply without accepted risk incorrectly succeeded');

requireAuth03(count($existing) > 0, 'no existing files found');
file_put_contents($existing[0], "\n// unknown local customization\n", FILE_APPEND);
[$rc, $output] = runAuth03Test($root, ['--check', '--target='.$target]);
requireAuth03($rc !== 0 && str_contains($output, 'differs from approved base'),
    'preflight did not protect a customized live file');

echo "PASS: AUTH-03 updater verified 10 paths, read-only preflight, explicit consent and unknown-file protection.\n";
