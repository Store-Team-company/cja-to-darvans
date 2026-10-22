<?php
/**
 * Exercise actual generated update archive on a simulated existing
 * split-directory SiteGround install. Does not access production.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'a14a6772c1483989c8f90aecc1a7b268cbe28d35';
$archive = $root.'/dist/data-pilot-saas-changes-only.zip';
$temp = sys_get_temp_dir().'/datapilot-saas-delta-'.bin2hex(random_bytes(6));

function assertSmoke(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('SaaS update smoke test failed: '.$message);
    }
}
function gitBase(string $root, string $ref): string
{
    $process = proc_open(
        ['git', 'show', $ref],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $root
    );
    assertSmoke(is_resource($process), 'git show cannot be launched');
    fclose($pipes[0]);
    $content = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSmoke(proc_close($process) === 0 && is_string($content), 'git show: '.$error);

    return $content;
}
function runUpdate(string $folder, array $args): array
{
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $folder.'/APPLY-SaaS-UPDATE.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $folder
    );
    assertSmoke(is_resource($process), 'Cannot run updater');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [$status, $output];
}

assertSmoke(mkdir($temp, 0700, true), 'Could not create scratch directory');
$zip = new ZipArchive();
assertSmoke($zip->open($archive) === true, 'Update ZIP missing');
assertSmoke($zip->extractTo($temp), 'Cannot unpack update');
$zip->close();

$app = $temp.'/data-pilot-app';
assertSmoke(mkdir($app.'/vendor', 0700, true), 'Cannot create fake vendor');
assertSmoke(mkdir($temp.'/public_html', 0700, true), 'Cannot create public webroot');
file_put_contents($app.'/artisan', "<?php // smoke placeholder\n");
file_put_contents($app.'/vendor/autoload.php', "<?php // smoke placeholder\n");
file_put_contents($app.'/.env', "APP_ENV=production\nAPP_KEY=sample\n");

$manifest = json_decode((string) file_get_contents($temp.'/update-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
assertSmoke(count($manifest['files'] ?? []) === 19, 'Expected exactly 19 source files');
foreach ($manifest['files'] as $entry) {
    if ($entry['mode'] !== 'replace') {
        continue;
    }
    $path = $entry['path'];
    $target = $app.'/'.$path;
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Unable to create smoke directory: '.$directory);
    }
    $oldContent = gitBase($root, $base.':'.$path);
    assertSmoke(hash('sha256', $oldContent) === $entry['original_sha256'], 'Base SHA mismatch: '.$path);
    file_put_contents($target, $oldContent);
}

[$status, $text] = runUpdate($temp, ['--check']);
assertSmoke($status === 0 && str_contains($text, 'Preflight PASS'), 'A correct legacy installation failed preflight: '.$text);
assertSmoke(!is_dir($temp.'/datapilot-private-backups'), 'Dry-run created source backup folder');

[$status, $text] = runUpdate($temp, ['--apply']);
assertSmoke($status !== 0 && str_contains($text, '--db-backed-up'), 'Missing DB-backup confirmation was not rejected');

$original = $app.'/app/Models/User.php';
file_put_contents($original, "\n// local modification\n", FILE_APPEND);
[$status, $text] = runUpdate($temp, ['--check']);
assertSmoke($status !== 0 && str_contains($text, 'customized'), 'Updater did not reject a customized file');
assertSmoke(!is_file($app.'/routes/saas.php'), 'A failed preflight modified the installation');

echo "PASS: Archive verified, 19 update paths, legacy checksums match, dry-run is read-only, DB backup confirmation enforced, local changes protected.\n";
