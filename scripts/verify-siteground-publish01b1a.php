<?php

/** BOTBLE-PUBLISH-01B1A safe changes-only SiteGround installer offline test. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'dbcdd07aeb98c1533b3365af61b81f60bbdbcaf4';
$tempRoot = sys_get_temp_dir().'/dp-publish01b1a-'.bin2hex(random_bytes(5));
$target = $tempRoot.'/www/staging.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-publish01b1a.php';

function p01b1aAssert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException('BOTBLE-PUBLISH-01B1A smoke FAIL: '.$message);
    }
}

function p01b1aExec(array $args, string $cwd): array
{
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    p01b1aAssert(is_resource($proc), 'Failed to spawn test process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $stdout];
}

function p01b1aWrite(string $file, string $contents): void
{
    p01b1aAssert(is_dir(dirname($file)) || mkdir(dirname($file), 0700, true), 'Fixture mkdir');
    p01b1aAssert(file_put_contents($file, $contents, LOCK_EX) !== false, 'Fixture write');
}

function p01b1aRun(array $options): array
{
    global $installer, $root, $target;
    return p01b1aExec([PHP_BINARY, $installer, ...$options, '--target='.$target], $root);
}

function p01b1aReject(array $options, string $needle): void
{
    [$code, $output] = p01b1aRun($options);
    p01b1aAssert($code !== 0 && str_contains($output, $needle), 'Expected error '.$needle.'; got: '.$output);
}

p01b1aAssert(mkdir($target.'/vendor', 0700, true), 'Fixture vendor');
p01b1aAssert(mkdir(dirname($target).'/public_html', 0700, true), 'Fixture web root');
foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $path) {
    p01b1aWrite($target.'/'.$path, 'private test sentinel');
}
p01b1aWrite($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);
$replaced = [
    'app/Http/Controllers/Saas/SaasProductEditorController.php',
];
$added = [];
$protected = ['.env', 'artisan', 'routes/api.php', 'routes/balenco-data-pilot.php'];
foreach (array_merge($replaced, ['routes/api.php', 'routes/balenco-data-pilot.php']) as $path) {
    [$status, $contents] = p01b1aExec(['git', 'show', $base.':'.$path], $root);
    p01b1aAssert($status === 0, 'Missing baseline '.$path);
    p01b1aWrite($target.'/'.$path, $contents);
}
$hashes = [];
foreach (array_merge($replaced, $protected) as $path) {
    $hashes[$path] = hash_file('sha256', $target.'/'.$path);
}
[$status, $result] = p01b1aRun(['--check']);
p01b1aAssert($status === 0 && str_contains($result, 'PASS: read-only BOTBLE-PUBLISH-01B1A preflight')
    && str_contains($result, 'replace app/Http/Controllers/Saas/SaasProductEditorController.php'),
    'Preflight failed: '.$result);
foreach ($hashes as $path => $hash) {
    p01b1aAssert(hash_file('sha256', $target.'/'.$path) === $hash, 'Preflight mutated '.$path);
}
foreach ($added as $path) {
    p01b1aAssert(!file_exists($target.'/'.$path), 'Preflight created '.$path);
}
p01b1aAssert(!is_dir(dirname($target).'/datapilot-private-backups'), 'Preflight created backup');
echo "PASS: clean read-only BOTBLE-PUBLISH-01B1A preflight.\n";

[$status, $sha] = p01b1aExec(['git', 'rev-parse', 'HEAD'], $root);
p01b1aAssert($status === 0, 'Cannot read HEAD');
$sha = trim($sha);
p01b1aReject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'exact --expected-head');
p01b1aReject(['--apply', '--expected-head='.$sha], 'exact --expected-head');

$path = $target.'/app/Http/Controllers/Saas/SaasProductEditorController.php';
$original = file_get_contents($path);
p01b1aWrite($path, $original."\n// unauthorized customization\n");
$hash = hash_file('sha256', $path);
p01b1aReject(['--check'], 'SiteGround customization in app/Http/Controllers/Saas/SaasProductEditorController.php');
p01b1aAssert(hash_file('sha256', $path) === $hash, 'Rejected modified custom file');
p01b1aWrite($path, $original);

rename($path, $target.'/app/Http/Controllers/Saas/unlinked-editor.php');
symlink($target.'/routes/api.php', $path);
p01b1aReject(['--check'], 'Missing/unsafe source or symlink: app/Http/Controllers/Saas/SaasProductEditorController.php');
unlink($path);
rename($target.'/app/Http/Controllers/Saas/unlinked-editor.php', $path);
echo "PASS: customizations, unknown files, and symlink protected.\n";

[$status, $out] = p01b1aRun(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
p01b1aAssert($status === 0 && str_contains($out, 'PASS: BOTBLE-PUBLISH-01B1A store authorization UI fix'), 'Safe offline apply failed: '.$out);
p01b1aAssert(!str_contains($out, 'Running migrations'), 'No schema migration is permitted in this sender update');
preg_match('/Private rollback backup: ([^\n]+)/', $out, $matched);
$backup = $matched[1] ?? '';
p01b1aAssert(is_dir($backup) && !str_contains($backup, '/public_html/'), 'Backup outside public root');
foreach ($replaced as $path) {
    p01b1aAssert(is_file($backup.'/'.$path), 'Backup missing '.$path);
    p01b1aAssert(hash_file('sha256', $backup.'/'.$path) === $hashes[$path], 'Backup hash changed '.$path);
    p01b1aAssert(hash_file('sha256', $target.'/'.$path) === hash_file('sha256', $root.'/'.$path),
        'Incorrect installed source '.$path);
}
foreach ($added as $path) {
    p01b1aAssert(is_file($target.'/'.$path), 'Added file missing '.$path);
}
foreach ($protected as $path) {
    p01b1aAssert(hash_file('sha256', $target.'/'.$path) === $hashes[$path], 'Protected file changed '.$path);
}
foreach ($added as $path) {
    p01b1aAssert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'), $path), 'Rollback listing missing '.$path);
}
echo "PASS: protected offline install, exact backups and no unrelated file changes.\n";
