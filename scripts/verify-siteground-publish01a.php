<?php

/** BOTBLE-PUBLISH-01A safe changes-only SiteGround installer offline test. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '2d0bb2038377ec4933bd0d62f3c93a549e24e621';
$tempRoot = sys_get_temp_dir().'/dp-publish01a-'.bin2hex(random_bytes(5));
$target = $tempRoot.'/www/staging.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-publish01a.php';

function p01aAssert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException('BOTBLE-PUBLISH-01A smoke FAIL: '.$message);
    }
}

function p01aExec(array $args, string $cwd): array
{
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    p01aAssert(is_resource($proc), 'Failed to spawn test process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $stdout];
}

function p01aWrite(string $file, string $contents): void
{
    p01aAssert(is_dir(dirname($file)) || mkdir(dirname($file), 0700, true), 'Fixture mkdir');
    p01aAssert(file_put_contents($file, $contents, LOCK_EX) !== false, 'Fixture write');
}

function p01aRun(array $options): array
{
    global $installer, $root, $target;
    return p01aExec([PHP_BINARY, $installer, ...$options, '--target='.$target], $root);
}

function p01aReject(array $options, string $needle): void
{
    [$code, $output] = p01aRun($options);
    p01aAssert($code !== 0 && str_contains($output, $needle), 'Expected error '.$needle.'; got: '.$output);
}

p01aAssert(mkdir($target.'/vendor', 0700, true), 'Fixture vendor');
p01aAssert(mkdir(dirname($target).'/public_html', 0700, true), 'Fixture web root');
foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $path) {
    p01aWrite($target.'/'.$path, 'private test sentinel');
}
p01aWrite($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);
$replaced = [
    'resources/views/saas/product-edit.blade.php',
    'routes/saas.php',
    'config/saas.php',
];
$added = [
    'app/Models/SaasBotbleProductDelivery.php',
    'app/Services/Saas/SaasBotbleDeliveryException.php',
    'app/Services/Saas/SaasBotbleDraftDelivery.php',
    'app/Http/Controllers/Saas/SaasBotbleDraftDeliveryController.php',
    'database/migrations/2026_10_23_000012_create_saas_botble_product_deliveries.php',
];
$protected = ['.env', 'artisan', 'routes/api.php', 'routes/balenco-data-pilot.php'];
foreach (array_merge($replaced, ['routes/api.php', 'routes/balenco-data-pilot.php']) as $path) {
    [$status, $contents] = p01aExec(['git', 'show', $base.':'.$path], $root);
    p01aAssert($status === 0, 'Missing baseline '.$path);
    p01aWrite($target.'/'.$path, $contents);
}
$hashes = [];
foreach (array_merge($replaced, $protected) as $path) {
    $hashes[$path] = hash_file('sha256', $target.'/'.$path);
}
[$status, $result] = p01aRun(['--check']);
p01aAssert($status === 0 && str_contains($result, 'PASS: read-only BOTBLE-PUBLISH-01A preflight')
    && str_contains($result, 'add app/Services/Saas/SaasBotbleDraftDelivery.php')
    && str_contains($result, 'replace resources/views/saas/product-edit.blade.php')
    && str_contains($result, 'add database/migrations/2026_10_23_000012_create_saas_botble_product_deliveries.php'),
    'Preflight failed: '.$result);
foreach ($hashes as $path => $hash) {
    p01aAssert(hash_file('sha256', $target.'/'.$path) === $hash, 'Preflight mutated '.$path);
}
foreach ($added as $path) {
    p01aAssert(!file_exists($target.'/'.$path), 'Preflight created '.$path);
}
p01aAssert(!is_dir(dirname($target).'/datapilot-private-backups'), 'Preflight created backup');
echo "PASS: clean read-only BOTBLE-PUBLISH-01A preflight.\n";

[$status, $sha] = p01aExec(['git', 'rev-parse', 'HEAD'], $root);
p01aAssert($status === 0, 'Cannot read HEAD');
$sha = trim($sha);
p01aReject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'exact --expected-head');
p01aReject(['--apply', '--expected-head='.$sha], 'exact --expected-head');

$path = $target.'/resources/views/saas/product-edit.blade.php';
$original = file_get_contents($path);
p01aWrite($path, $original."\n<!-- staging customization -->");
$hash = hash_file('sha256', $path);
p01aReject(['--check'], 'SiteGround customization in resources/views/saas/product-edit.blade.php');
p01aAssert(hash_file('sha256', $path) === $hash, 'Rejected modified custom file');
p01aWrite($path, $original);

$unexpected = $target.'/'.$added[0];
p01aWrite($unexpected, '<?php // unknown SiteGround file');
p01aReject(['--check'], 'SiteGround customization in '.$added[0]);
unlink($unexpected);

$path = $target.'/routes/saas.php';
rename($path, $target.'/routes/unlinked-saas.php');
symlink($target.'/routes/api.php', $path);
p01aReject(['--check'], 'Missing/unsafe source or symlink: routes/saas.php');
unlink($path);
rename($target.'/routes/unlinked-saas.php', $path);
echo "PASS: customizations, unknown files, and symlink protected.\n";

[$status, $out] = p01aRun(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
p01aAssert($status === 0 && str_contains($out, 'PASS: BOTBLE-PUBLISH-01A signed stage-only sandbox review inbox'), 'Safe offline apply failed: '.$out);
p01aAssert(str_contains($out, '2026_10_23_000012_create_saas_botble_product_deliveries.php'), 'Migration was not dispatched');
preg_match('/Private rollback backup: ([^\n]+)/', $out, $matched);
$backup = $matched[1] ?? '';
p01aAssert(is_dir($backup) && !str_contains($backup, '/public_html/'), 'Backup outside public root');
foreach ($replaced as $path) {
    p01aAssert(is_file($backup.'/'.$path), 'Backup missing '.$path);
    p01aAssert(hash_file('sha256', $backup.'/'.$path) === $hashes[$path], 'Backup hash changed '.$path);
    p01aAssert(hash_file('sha256', $target.'/'.$path) === hash_file('sha256', $root.'/'.$path),
        'Incorrect installed source '.$path);
}
foreach ($added as $path) {
    p01aAssert(is_file($target.'/'.$path), 'Added file missing '.$path);
}
foreach ($protected as $path) {
    p01aAssert(hash_file('sha256', $target.'/'.$path) === $hashes[$path], 'Protected file changed '.$path);
}
foreach ($added as $path) {
    p01aAssert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'), $path), 'Rollback listing missing '.$path);
}
echo "PASS: protected offline install, migration, exact backups and no unrelated file changes.\n";
