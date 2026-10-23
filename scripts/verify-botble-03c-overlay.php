<?php

/** Offline safety QA against exact v0.3.1 PHP core, matching observed live v0.3.2 hashes. */
declare(strict_types=1);
$root = dirname(__DIR__);
$package = $root.'/dist/datapilot-botble-03c-overlay.zip';
if (!is_file($package) || !class_exists(ZipArchive::class)) {
    throw new RuntimeException('Build overlay ZIP before verifier.');
}
$testRoot = sys_get_temp_dir().'/datapilot-03c-'.bin2hex(random_bytes(6));
$home = $testRoot.'/home';
$target = $testRoot.'/sandbox.daystart.pk/public_html';
$plugin = $target.'/platform/plugins/datapilot-connector';
if (!mkdir($home, 0700, true) || !mkdir($plugin, 0700, true)) {
    throw new RuntimeException('Cannot create isolated test environment.');
}
$zip = new ZipArchive();
if ($zip->open($package) !== true || !$zip->extractTo($testRoot.'/release') || !$zip->close()) {
    throw new RuntimeException('Cannot extract test overlay ZIP.');
}
$installRoot = $testRoot.'/release/botble03c-overlay';
$script = $installRoot.'/installer.php';
$metadata = json_decode((string) file_get_contents($installRoot.'/botble03c-manifest.json'), true);
$sha = $metadata['source_head'] ?? null;
if (!is_string($sha) || strlen($sha) !== 40) {
    throw new RuntimeException('Source HEAD metadata unavailable.');
}

function b03cTestAssert(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException('BOTBLE-03C OFFLINE QA FAIL: '.$message);
    }
}
function b03cTestExec(array $args, string $cwd): array
{
    $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    b03cTestAssert(is_resource($proc), 'process could not start');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
}
function b03cTestWrite(string $path, string $content): void
{
    b03cTestAssert(is_dir(dirname($path)) || mkdir(dirname($path), 0700, true), 'Fixture parent directory');
    b03cTestAssert(file_put_contents($path, $content, LOCK_EX) !== false, 'Fixture write');
}
function b03cTestRun(array $params): array
{
    global $target, $script, $testRoot;
    return b03cTestExec([PHP_BINARY, $script, ...$params, '--target='.$target], $testRoot);
}
function b03cTestReject(array $params, string $message): void
{
    [$status, $out] = b03cTestRun($params);
    b03cTestAssert($status !== 0 && str_contains($out, $message),
        'Expected abort '.$message.' but received '.$out);
}
$oldHome = getenv('HOME');
putenv('HOME='.$home);
b03cTestWrite($target.'/.env', 'APP_KEY=fixture-testing-only');
b03cTestWrite($target.'/artisan', <<<'PHP'
<?php
echo 'FIXTURE ARTISAN '.implode(' ', array_slice($argv, 1)).PHP_EOL;
PHP
);
$baseline = '51b0ed2b15892516fafc3c61f384c16ac3000ce3';
$pluginFiles = [
    'src/Http/Controllers/DraftInboxController.php',
    'src/Services/CatalogImportService.php',
    'src/Services/ConnectorSigning.php',
    'config/datapilot.php',
    'plugin.json',
    'resources/views/incoming-products.blade.php',
    'resources/views/settings.blade.php',
];
foreach ($pluginFiles as $path) {
    [$code, $contents] = b03cTestExec([
        'git', 'show', $baseline.':integrations/botble-connector/'.$path,
    ], $root);
    b03cTestAssert($code === 0, 'Fixture source missing '.$path);
    if ($path === 'plugin.json') {
        $contents = str_replace('"version": "0.3.1"', '"version": "0.3.2"', $contents);
        $contents = str_replace('Native Botble admin pairing wizard', 'Preserve-local-032 Native Botble admin pairing wizard', $contents);
    }
    if ($path === 'config/datapilot.php') {
        $contents = str_replace("];", "    'local_032_only' => true,\n];", $contents);
    }
    b03cTestWrite($plugin.'/'.$path, $contents);
}
$replacements = [
    'src/Http/Controllers/DraftInboxController.php',
    'src/Services/CatalogImportService.php',
    'config/datapilot.php',
    'plugin.json',
];
$untouched = [
    '.env' => $target.'/.env',
    'src/Services/ConnectorSigning.php' => $plugin.'/src/Services/ConnectorSigning.php',
    'resources/views/incoming-products.blade.php' => $plugin.'/resources/views/incoming-products.blade.php',
    'resources/views/settings.blade.php' => $plugin.'/resources/views/settings.blade.php',
];
$hashes = [];
foreach (array_merge($replacements, array_keys($untouched)) as $path) {
    $source = $untouched[$path] ?? $plugin.'/'.$path;
    $hashes[$path] = hash_file('sha256', $source);
}
b03cTestAssert($hashes['src/Http/Controllers/DraftInboxController.php']
    === '5ad2798038c6dd6e41c7956a53be1e97ffef0a118447d85eceddf775c283dc7f',
    'Observed live v0.3.2 inbox baseline mismatch');
b03cTestAssert($hashes['src/Services/CatalogImportService.php']
    === '28f94508e6f79b148625c271573eb2705b9d7c10d62b3405d4fab0b54fa3c5e9',
    'Observed live v0.3.2 native importer baseline mismatch');

[$status, $out] = b03cTestRun(['--check']);
b03cTestAssert($status === 0 && str_contains($out, 'PASS: read-only BOTBLE-03C overlay preflight'),
    'Read-only preflight: '.$out);
b03cTestAssert(!is_file($plugin.'/src/Services/SignedDraftHtmlSanitizer.php'), 'Preflight created a source file');
b03cTestAssert(!is_dir($home.'/datapilot-private-backups'), 'Preflight created a backup');
foreach ($hashes as $path => $hash) {
    b03cTestAssert(hash_file('sha256', $untouched[$path] ?? $plugin.'/'.$path) === $hash,
        'Preflight changed '.$path);
}
echo "PASS: exact live-file baselines and read-only preflight.\n";

b03cTestReject(['--apply','--backup-confirmed','--expected-head='.str_repeat('0', 40)], 'exact release --expected-head');
b03cTestReject(['--apply','--expected-head='.$sha], 'exact release --expected-head');
$changed = $plugin.'/src/Http/Controllers/DraftInboxController.php';
$original = (string) file_get_contents($changed);
b03cTestWrite($changed, $original."\n// local customization\n");
b03cTestReject(['--check'], 'Unexpected existing connector customization');
b03cTestWrite($changed, $original);

$config = $plugin.'/config/datapilot.php';
$originalConfig = (string) file_get_contents($config);
b03cTestWrite($config, str_replace("'inbound_enabled'", "'renamed_enabled'", $originalConfig));
b03cTestReject(['--check'], 'Plugin configuration differs');
b03cTestWrite($config, $originalConfig);

[$status, $out] = b03cTestRun(['--apply','--backup-confirmed','--expected-head='.$sha]);
b03cTestAssert($status === 0 && str_contains($out, 'PASS: Botble Connector 0.3.3 reviewed-inbox overlay installed'),
    'Offline apply failed: '.$out);
preg_match('/Private rollback backup: ([^\n]+)/', $out, $match);
$backup = $match[1] ?? '';
b03cTestAssert(is_dir($backup) && !str_contains($backup, '/public_html/'),
    'Missing private rollback backup');
foreach ($replacements as $path) {
    b03cTestAssert(is_file($backup.'/'.$path), 'Backup missing '.$path);
    b03cTestAssert(hash_file('sha256', $backup.'/'.$path) === $hashes[$path],
        'Backup content mismatch '.$path);
}
foreach ($untouched as $path => $source) {
    b03cTestAssert(hash_file('sha256', $source) === $hashes[$path], 'Protected file changed '.$path);
}
foreach ($metadata['payload_hashes'] as $path => $hash) {
    b03cTestAssert(hash_file('sha256', $plugin.'/'.$path) === $hash,
        'Installed overlay file differs from reviewed ZIP: '.$path);
}
b03cTestAssert(str_contains((string) file_get_contents($config), "'local_032_only' => true"),
    'Existing locally customized configuration lost');
b03cTestAssert(str_contains((string) file_get_contents($config), "'variant_drafts_enabled'"),
    'New disabled-by-default variant flag missing');
$json = json_decode((string) file_get_contents($plugin.'/plugin.json'), true);
b03cTestAssert(($json['version'] ?? null) === '0.3.3'
    && str_contains($json['description'], 'Preserve-local-032'),
    'Did not preserve live connector metadata');
b03cTestAssert(str_contains((string) file_get_contents($backup.'/ADDED_FILES.txt'),
    'src/Services/SignedDraftHtmlSanitizer.php'), 'Sanitizer addition omitted in backup');
echo "PASS: exact-hash private backups, admin UI retained and safe variant patch installed.\n";
putenv($oldHome === false ? 'HOME' : 'HOME='.$oldHome);
