<?php

/** Offline, isolated PRODUCT-02 installer smoke: no live SiteGround reads/writes. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'f9bdb52ea973b967d59df9000ab2b0150827f0dd';
$temp = sys_get_temp_dir().'/datapilot-product02-'.bin2hex(random_bytes(5));
$target = $temp.'/www/test.example/data-pilot-app';
$script = $root.'/scripts/deploy-siteground-product02.php';

function p02Assert(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException('PRODUCT-02 verifier FAIL: '.$message);
    }
}

function p02Cmd(array $args, string $cwd): array
{
    $proc = proc_open($args, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $cwd);
    p02Assert(is_resource($proc), 'Unable to spawn test subprocess');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $output];
}

function p02Write(string $path, string $value): void
{
    p02Assert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'Create fixture directory');
    p02Assert(file_put_contents($path, $value, LOCK_EX) !== false, 'Write fixture');
}

function p02Run(array $flags): array
{
    global $root, $target, $script;
    return p02Cmd([PHP_BINARY, $script, ...$flags, '--target='.$target], $root);
}

function p02Reject(array $flags, string $message): void
{
    [$status, $output] = p02Run($flags);
    p02Assert($status !== 0 && str_contains($output, $message), 'Expected rejection '.$message.': '.$output);
}

p02Assert(mkdir($target.'/vendor', 0700, true), 'Fixture vendor');
p02Assert(mkdir(dirname($target).'/public_html', 0700, true), 'Fixture public_html');
foreach (['.env','.siteground-layout','vendor/autoload.php'] as $file) {
    p02Write($target.'/'.$file, 'fixture-sentinel');
}
p02Write($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE LARAVEL '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);
$manifest = [
    'app/Services/Saas/SaasCjCatalogBrowser.php',
    'app/Http/Controllers/Saas/SaasCjProductController.php',
    'app/Http/Controllers/Saas/SaasPortalController.php',
    'resources/views/saas/products.blade.php',
    'routes/saas.php',
];
$protected = ['routes/balenco-data-pilot.php', 'routes/api.php', '.env', 'artisan'];
foreach (array_slice($manifest, 1) as $file) {
    [$status, $original] = p02Cmd(['git', 'show', $base.':'.$file], $root);
    p02Assert($status === 0, 'Baseline missing '.$file);
    p02Write($target.'/'.$file, $original);
}
foreach (array_slice($protected, 0, 2) as $file) {
    [$status, $original] = p02Cmd(['git', 'show', $base.':'.$file], $root);
    p02Assert($status === 0, 'Protected baseline missing '.$file);
    p02Write($target.'/'.$file, $original);
}
$hashes = [];
foreach (array_merge(array_slice($manifest, 1), $protected) as $file) {
    $hashes[$file] = hash_file('sha256', $target.'/'.$file);
}
[$status, $output] = p02Run(['--check']);
p02Assert($status === 0 && str_contains($output, 'PASS: read-only PRODUCT-02 preflight')
    && str_contains($output, 'add app/Services/Saas/SaasCjCatalogBrowser.php')
    && str_contains($output, 'replace routes/saas.php'), 'Read-only preflight: '.$output);
foreach ($hashes as $path => $expected) {
    p02Assert(hash_file('sha256', $target.'/'.$path) === $expected, 'Read-only modified '.$path);
}
p02Assert(!is_file($target.'/app/Services/Saas/SaasCjCatalogBrowser.php'), 'Preflight created a PHP file');
p02Assert(!is_dir(dirname($target).'/datapilot-private-backups'), 'Preflight created a backup');
echo "PASS: strict read-only preflight and manifest.\n";

[$status, $sha] = p02Cmd(['git','rev-parse','HEAD'], $root);
p02Assert($status === 0 && preg_match('/^[0-9a-f]{40}$/D', trim($sha)) === 1, 'Git HEAD');
$sha = trim($sha);
p02Reject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'exact --expected-head');
p02Reject(['--apply', '--expected-head='.$sha], 'exact --expected-head');

$path = $target.'/routes/saas.php';
$original = file_get_contents($path);
p02Write($path, $original."\n// Customized SiteGround; must be retained.\n");
$hash = hash_file('sha256', $path);
p02Reject(['--check'], 'SiteGround customization in routes/saas.php');
p02Assert(hash_file('sha256', $path) === $hash, 'Customized route changed on abort');
p02Write($path, $original);

p02Write($target.'/app/Services/Saas/SaasCjCatalogBrowser.php', '<?php // Unexpected previous class');
p02Reject(['--check'], 'SiteGround customization in app/Services/Saas/SaasCjCatalogBrowser.php');
unlink($target.'/app/Services/Saas/SaasCjCatalogBrowser.php');

rename($path, $target.'/routes/saas-unlinked.php');
symlink($target.'/routes/api.php', $path);
p02Reject(['--check'], 'Missing/unsafe source or symlink');
unlink($path);
rename($target.'/routes/saas-unlinked.php', $path);
echo "PASS: refuses customized source, unknown new class and symlink.\n";

[$status, $output] = p02Run(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
p02Assert($status === 0 && str_contains($output, 'PASS: PRODUCT-02 selective CJ search'), 'Sandbox install failed: '.$output);
preg_match('/Private rollback backup: ([^\n]+)/', $output, $matches);
$backup = $matches[1] ?? '';
p02Assert(is_dir($backup) && !str_contains($backup, '/public_html/'), 'Private rollback missing');
foreach (array_slice($manifest, 1) as $file) {
    p02Assert(is_file($backup.'/'.$file) && hash_file('sha256', $backup.'/'.$file) === $hashes[$file],
        'Backup missing '.$file);
    p02Assert(hash_file('sha256', $target.'/'.$file) === hash_file('sha256', $root.'/'.$file),
        'Source not installed '.$file);
}
p02Assert(is_file($target.'/'.$manifest[0]), 'New browser service not installed');
p02Assert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'), $manifest[0]),
    'Added manifest absent');
foreach ($protected as $file) {
    p02Assert(hash_file('sha256', $target.'/'.$file) === $hashes[$file], 'Protected file changed '.$file);
}
echo "PASS: isolated installer, private backups, rollback manifest, untouched admin/auth/env.\n";
