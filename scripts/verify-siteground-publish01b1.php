<?php

/** BOTBLE-PUBLISH-01B1 safe changes-only SiteGround installer offline test. */
declare(strict_types=1);

$root = dirname(__DIR__);
$base = '783c2a297d7f0680bbdd987aa3e047f2859e2f07';
$tempRoot = sys_get_temp_dir().'/dp-publish01b1-'.bin2hex(random_bytes(5));
$target = $tempRoot.'/www/staging.example/data-pilot-app';
$installer = $root.'/scripts/deploy-siteground-publish01b1.php';

function p01b1Assert(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException('BOTBLE-PUBLISH-01B1 smoke FAIL: '.$message);
    }
}

function p01b1Exec(array $args, string $cwd): array
{
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    p01b1Assert(is_resource($proc), 'Failed to spawn test process');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $stdout];
}

function p01b1Write(string $file, string $contents): void
{
    p01b1Assert(is_dir(dirname($file)) || mkdir(dirname($file), 0700, true), 'Fixture mkdir');
    p01b1Assert(file_put_contents($file, $contents, LOCK_EX) !== false, 'Fixture write');
}

function p01b1Run(array $options): array
{
    global $installer, $root, $target;
    return p01b1Exec([PHP_BINARY, $installer, ...$options, '--target='.$target], $root);
}

function p01b1Reject(array $options, string $needle): void
{
    [$code, $output] = p01b1Run($options);
    p01b1Assert($code !== 0 && str_contains($output, $needle), 'Expected error '.$needle.'; got: '.$output);
}

p01b1Assert(mkdir($target.'/vendor', 0700, true), 'Fixture vendor');
p01b1Assert(mkdir(dirname($target).'/public_html', 0700, true), 'Fixture web root');
foreach (['.env', '.siteground-layout', 'vendor/autoload.php'] as $path) {
    p01b1Write($target.'/'.$path, 'private test sentinel');
}
p01b1Write($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);
$replaced = [
    'app/Services/Saas/SaasBotbleDraftDelivery.php',
    'app/Http/Controllers/Saas/SaasBotbleDraftDeliveryController.php',
    'resources/views/saas/product-edit.blade.php',
    'config/saas.php',
];
$added = [
    'app/Services/Saas/SaasBotbleVariantDraftPayload.php',
];
$protected = ['.env', 'artisan', 'routes/api.php', 'routes/balenco-data-pilot.php'];
foreach (array_merge($replaced, ['routes/api.php', 'routes/balenco-data-pilot.php']) as $path) {
    [$status, $contents] = p01b1Exec(['git', 'show', $base.':'.$path], $root);
    p01b1Assert($status === 0, 'Missing baseline '.$path);
    p01b1Write($target.'/'.$path, $contents);
}
$hashes = [];
foreach (array_merge($replaced, $protected) as $path) {
    $hashes[$path] = hash_file('sha256', $target.'/'.$path);
}
[$status, $result] = p01b1Run(['--check']);
p01b1Assert($status === 0 && str_contains($result, 'PASS: read-only BOTBLE-PUBLISH-01B1 preflight')
    && str_contains($result, 'add app/Services/Saas/SaasBotbleVariantDraftPayload.php')
    && str_contains($result, 'replace resources/views/saas/product-edit.blade.php')
    && str_contains($result, 'replace config/saas.php'),
    'Preflight failed: '.$result);
foreach ($hashes as $path => $hash) {
    p01b1Assert(hash_file('sha256', $target.'/'.$path) === $hash, 'Preflight mutated '.$path);
}
foreach ($added as $path) {
    p01b1Assert(!file_exists($target.'/'.$path), 'Preflight created '.$path);
}
p01b1Assert(!is_dir(dirname($target).'/datapilot-private-backups'), 'Preflight created backup');
echo "PASS: clean read-only BOTBLE-PUBLISH-01B1 preflight.\n";

[$status, $sha] = p01b1Exec(['git', 'rev-parse', 'HEAD'], $root);
p01b1Assert($status === 0, 'Cannot read HEAD');
$sha = trim($sha);
p01b1Reject(['--apply', '--backup-confirmed', '--expected-head='.str_repeat('0', 40)], 'exact --expected-head');
p01b1Reject(['--apply', '--expected-head='.$sha], 'exact --expected-head');

$path = $target.'/resources/views/saas/product-edit.blade.php';
$original = file_get_contents($path);
p01b1Write($path, $original."\n<!-- staging customization -->");
$hash = hash_file('sha256', $path);
p01b1Reject(['--check'], 'SiteGround customization in resources/views/saas/product-edit.blade.php');
p01b1Assert(hash_file('sha256', $path) === $hash, 'Rejected modified custom file');
p01b1Write($path, $original);

$unexpected = $target.'/'.$added[0];
p01b1Write($unexpected, '<?php // unknown SiteGround file');
p01b1Reject(['--check'], 'SiteGround customization in '.$added[0]);
unlink($unexpected);

$path = $target.'/routes/saas.php';
rename($path, $target.'/routes/unlinked-saas.php');
symlink($target.'/routes/api.php', $path);
p01b1Reject(['--check'], 'Missing/unsafe source or symlink: routes/saas.php');
unlink($path);
rename($target.'/routes/unlinked-saas.php', $path);
echo "PASS: customizations, unknown files, and symlink protected.\n";

[$status, $out] = p01b1Run(['--apply', '--backup-confirmed', '--expected-head='.$sha]);
p01b1Assert($status === 0 && str_contains($out, 'PASS: BOTBLE-PUBLISH-01B1 signed complete-variant staging code'), 'Safe offline apply failed: '.$out);
p01b1Assert(str_contains($out, '2026_10_23_000012_create_saas_botble_product_deliveries.php'), 'Migration was not dispatched');
preg_match('/Private rollback backup: ([^\n]+)/', $out, $matched);
$backup = $matched[1] ?? '';
p01b1Assert(is_dir($backup) && !str_contains($backup, '/public_html/'), 'Backup outside public root');
foreach ($replaced as $path) {
    p01b1Assert(is_file($backup.'/'.$path), 'Backup missing '.$path);
    p01b1Assert(hash_file('sha256', $backup.'/'.$path) === $hashes[$path], 'Backup hash changed '.$path);
    p01b1Assert(hash_file('sha256', $target.'/'.$path) === hash_file('sha256', $root.'/'.$path),
        'Incorrect installed source '.$path);
}
foreach ($added as $path) {
    p01b1Assert(is_file($target.'/'.$path), 'Added file missing '.$path);
}
foreach ($protected as $path) {
    p01b1Assert(hash_file('sha256', $target.'/'.$path) === $hashes[$path], 'Protected file changed '.$path);
}
foreach ($added as $path) {
    p01b1Assert(str_contains(file_get_contents($backup.'/ADDED_FILES.txt'), $path), 'Rollback listing missing '.$path);
}
echo "PASS: protected offline install, migration, exact backups and no unrelated file changes.\n";
