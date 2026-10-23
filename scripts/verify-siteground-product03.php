<?php

/** PRODUCT-03 safe changes-only SiteGround installer offline test. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '5c62886dcfcfc91dbe9066d36d536aef4e4e19d8';
$tempRoot = sys_get_temp_dir().'/dp-product03-'.bin2hex(random_bytes(5));
$target = $tempRoot.'/www/staging.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-product03.php';

function p03Assert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException('PRODUCT-03 smoke FAIL: '.$message);
    }
}

function p03Exec(array $args, string $cwd): array
{
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    p03Assert(is_resource($proc), 'Failed to spawn test process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $stdout];
}

function p03Write(string $file, string $contents): void
{
    p03Assert(is_dir(dirname($file)) || mkdir(dirname($file), 0700, true), 'Fixture mkdir');
    p03Assert(file_put_contents($file, $contents, LOCK_EX) !== false, 'Fixture write');
}

function p03Run(array $options): array
{
    global $installer, $root, $target;
    return p03Exec([PHP_BINARY, $installer, ...$options, '--target='.$target], $root);
}

function p03Reject(array $options, string $needle): void
{
    [$code, $output] = p03Run($options);
    p03Assert($code !== 0 && str_contains($output, $needle), 'Expected error '.$needle.'; got: '.$output);
}

p03Assert(mkdir($target.'/vendor', 0700, true), 'Fixture vendor');
p03Assert(mkdir(dirname($target).'/public_html', 0700, true), 'Fixture web root');
foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $path) {
    p03Write($target.'/'.$path, 'private test sentinel');
}
p03Write($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);
$replaced = [
    'app/Http/Controllers/Saas/SaasPortalController.php',
    'app/Http/Controllers/Saas/SaasCjProductController.php',
    'app/Services/Saas/SaasCjCatalogBrowser.php',
    'app/Models/SaasProduct.php',
    'resources/views/saas/products.blade.php',
    'resources/views/saas/layout.blade.php',
    'routes/saas.php',
];
$added = [
    'app/Http/Controllers/Saas/SaasProductEditorController.php',
    'resources/views/saas/cj-import.blade.php',
    'resources/views/saas/product-edit.blade.php',
    'database/migrations/2026_10_23_000010_add_saas_product_merchant_edits.php',
];
$protected = ['.env', 'artisan', 'routes/api.php', 'routes/balenco-data-pilot.php'];
foreach (array_merge($replaced, ['routes/api.php', 'routes/balenco-data-pilot.php']) as $path) {
    [$status, $contents] = p03Exec(['git', 'show', $base.':'.$path], $root);
    p03Assert($status === 0, 'Missing baseline '.$path);
    p03Write($target.'/'.$path, $contents);
}
$hashes = [];
foreach (array_merge($replaced, $protected) as $path) {
    $hashes[$path] = hash_file('sha256', $target.'/'.$path);
}
[$status, $result] = p03Run(['--check']);
p03Assert($status === 0 && str_contains($result, 'PASS: read-only PRODUCT-03 preflight')
    && str_contains($result, 'add resources/views/saas/cj-import.blade.php')
    && str_contains($result, 'replace resources/views/saas/products.blade.php')
    && str_contains($result, 'add database/migrations/2026_10_23_000010_add_saas_product_merchant_edits.php'),
    'Preflight failed: '.$result);
foreach ($hashes as $path => $hash) {
    p03Assert(hash_file('sha256', $target.'/'.$path) === $hash, 'Preflight mutated '.$path);
}
foreach ($added as $path) {
    p03Assert(!file_exists($target.'/'.$path), 'Preflight created '.$path);
}
p03Assert(!is_dir(dirname($target).'/datapilot-private-backups'), 'Preflight created backup');
echo "PASS: clean read-only PRODUCT-03 preflight.\n";

[$status, $sha] = p03Exec(['git', 'rev-parse', 'HEAD'], $root);
p03Assert($status === 0, 'Cannot read HEAD');
$sha = trim($sha);
p03Reject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'exact --expected-head');
p03Reject(['--apply', '--expected-head='.$sha], 'exact --expected-head');

$path = $target.'/resources/views/saas/products.blade.php';
$original = file_get_contents($path);
p03Write($path, $original."\n<!-- staging customization -->");
$hash = hash_file('sha256', $path);
p03Reject(['--check'], 'SiteGround customization in resources/views/saas/products.blade.php');
p03Assert(hash_file('sha256', $path) === $hash, 'Rejected modified custom file');
p03Write($path, $original);

$unexpected = $target.'/'.$added[0];
p03Write($unexpected, '<?php // unknown SiteGround file');
p03Reject(['--check'], 'SiteGround customization in '.$added[0]);
unlink($unexpected);

$path = $target.'/routes/saas.php';
rename($path, $target.'/routes/unlinked-saas.php');
symlink($target.'/routes/api.php', $path);
p03Reject(['--check'], 'Missing/unsafe source or symlink: routes/saas.php');
unlink($path);
rename($target.'/routes/unlinked-saas.php', $path);
echo "PASS: customizations, unknown files, and symlink protected.\n";

[$status, $out] = p03Run(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
p03Assert($status === 0 && str_contains($out, 'PASS: PRODUCT-03 customer catalog'), 'Safe offline apply failed: '.$out);
p03Assert(str_contains($out, '2026_10_23_000010_add_saas_product_merchant_edits.php'), 'Migration was not dispatched');
preg_match('/Private rollback backup: ([^\n]+)/', $out, $matched);
$backup = $matched[1] ?? '';
p03Assert(is_dir($backup) && !str_contains($backup, '/public_html/'), 'Backup outside public root');
foreach ($replaced as $path) {
    p03Assert(is_file($backup.'/'.$path), 'Backup missing '.$path);
    p03Assert(hash_file('sha256', $backup.'/'.$path) === $hashes[$path], 'Backup hash changed '.$path);
    p03Assert(hash_file('sha256', $target.'/'.$path) === hash_file('sha256', $root.'/'.$path),
        'Incorrect installed source '.$path);
}
foreach ($added as $path) {
    p03Assert(is_file($target.'/'.$path), 'Added file missing '.$path);
}
foreach ($protected as $path) {
    p03Assert(hash_file('sha256', $target.'/'.$path) === $hashes[$path], 'Protected file changed '.$path);
}
foreach ($added as $path) {
    p03Assert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'), $path), 'Rollback listing missing '.$path);
}
echo "PASS: protected offline install, migration, exact backups and no unrelated file changes.\n";
