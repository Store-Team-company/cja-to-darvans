<?php

/**
 * BOTBLE-03C: tightly scoped v0.3.2 -> v0.3.3 signed-inbox overlay.
 *
 * Execute ONLY from the separately packaged private overlay directory on
 * sandbox.daystart.pk. This does not install full plugin sources, change
 * Botble ecommerce records, touch .env or enable any feature flag.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}

function b03cAbort(string $reason): never
{
    fwrite(STDERR, "BOTBLE-03C ABORT: ".$reason.PHP_EOL);
    exit(2);
}
function b03cArg(array $args, string $name): string
{
    foreach ($args as $arg) {
        if (str_starts_with($arg, $name.'=')) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return '';
}
function b03cSafePath(string $path, string $base): bool
{
    if (!str_starts_with($path, $base.'/')) {
        return false;
    }
    $current = $base;
    foreach (explode('/', substr($path, strlen($base) + 1)) as $piece) {
        if ($piece === '' || $piece === '.' || $piece === '..') {
            return false;
        }
        $current .= '/'.$piece;
        if (is_link($current)) {
            return false;
        }
    }
    return true;
}

$check = in_array('--check', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($check === $apply) {
    b03cAbort('Specify exactly one of --check or --apply.');
}
$target = realpath(b03cArg($argv, '--target'));
$home = realpath((string) getenv('HOME'));
if (!$target || !$home || basename($target) !== 'public_html'
    || basename(dirname($target)) !== 'sandbox.daystart.pk'
    || !is_file($target.'/artisan') || !is_file($target.'/.env')
    || !is_dir($target.'/platform/plugins/datapilot-connector')) {
    b03cAbort('Unrecognized sandbox.daystart.pk Botble installation.');
}
$plugin = realpath($target.'/platform/plugins/datapilot-connector');
if (!$plugin || $plugin !== $target.'/platform/plugins/datapilot-connector') {
    b03cAbort('Unsafe or relocated connector plugin directory.');
}
$packageRoot = realpath(__DIR__);
$manifestPath = $packageRoot.'/botble03c-manifest.json';
if (!$packageRoot || !is_file($manifestPath) || is_link($manifestPath)) {
    b03cAbort('Missing signed-off overlay manifest.');
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || ($manifest['target_version'] ?? null) !== '0.3.3'
    || !preg_match('/^[0-9a-f]{40}$/D', (string) ($manifest['source_head'] ?? ''))) {
    b03cAbort('Invalid overlay release metadata.');
}
if ($apply && (!in_array('--backup-confirmed', $argv, true)
    || !hash_equals($manifest['source_head'], b03cArg($argv, '--expected-head')))) {
    b03cAbort('Apply requires --backup-confirmed and exact release --expected-head.');
}

$files = [
    // Exact observed, verified LIVE v0.3.2 baselines, confirmed 2026-10-23.
    'src/Http/Controllers/DraftInboxController.php' =>
        '5ad2798038c6dd6e41c7956a53be1e97ffef0a118447d85eceddf775c283dc7f',
    'src/Services/CatalogImportService.php' =>
        '28f94508e6f79b148625c271573eb2705b9d7c10d62b3405d4fab0b54fa3c5e9',
];
$addedFile = 'src/Services/SignedDraftHtmlSanitizer.php';
$replacement = [];
foreach ($files as $relative => $baselineHash) {
    $from = $packageRoot.'/payload/'.$relative;
    $to = $plugin.'/'.$relative;
    if (!b03cSafePath($from, $packageRoot) || !b03cSafePath($to, $plugin)
        || !is_file($from) || !is_file($to) || is_link($from) || is_link($to)
        || !hash_equals((string) ($manifest['payload_hashes'][$relative] ?? ''), hash_file('sha256', $from))) {
        b03cAbort('Unsafe or unverifiable file: '.$relative);
    }
    if (!hash_equals($baselineHash, hash_file('sha256', $to))) {
        b03cAbort('Unexpected existing connector customization: '.$relative);
    }
    $replacement[$relative] = file_get_contents($from);
}
$from = $packageRoot.'/payload/'.$addedFile;
$to = $plugin.'/'.$addedFile;
if (!b03cSafePath($from, $packageRoot) || !b03cSafePath($to, $plugin)
    || !is_file($from) || is_file($to) || is_link($from) || is_link($to)
    || !hash_equals((string) ($manifest['payload_hashes'][$addedFile] ?? ''), hash_file('sha256', $from))) {
    b03cAbort('Sanitizer already exists or its packaged hash is invalid.');
}
$replacement[$addedFile] = file_get_contents($from);

$configPath = $plugin.'/config/datapilot.php';
$versionPath = $plugin.'/plugin.json';
if (!b03cSafePath($configPath, $plugin) || !b03cSafePath($versionPath, $plugin)
    || !is_file($configPath) || !is_file($versionPath)) {
    b03cAbort('Unsafe plugin configuration or manifest.');
}
$config = (string) file_get_contents($configPath);
$mark = "'inbound_enabled' => (bool) env('DATAPILOT_INBOUND_DRAFTS_ENABLED', false),";
if (substr_count($config, $mark) !== 1
    || str_contains($config, 'variant_drafts_enabled')
    || !str_contains($config, "'catalog_import_enabled' => (bool) env('DATAPILOT_CATALOG_IMPORT_ENABLED', false),")) {
    b03cAbort('Plugin configuration differs from expected safe extension point.');
}
$replacement['config/datapilot.php'] = str_replace($mark, $mark.PHP_EOL
    ."    // Complete signed variants; separate feature flag remains default OFF.".PHP_EOL
    ."    'variant_drafts_enabled' => (bool) env('DATAPILOT_VARIANT_DRAFTS_ENABLED', false),", $config);

$json = (string) file_get_contents($versionPath);
$oldManifest = json_decode($json, true);
if (!is_array($oldManifest) || ($oldManifest['version'] ?? null) !== '0.3.2'
    || ($oldManifest['namespace'] ?? null) !== 'Botble\\DatapilotConnector\\') {
    b03cAbort('Expected installed Botble Connector v0.3.2 with correct namespace.');
}
$newJson = preg_replace_callback('/("version"\s*:\s*")0\.3\.2(")/',
    static fn (array $parts): string => $parts[1].'0.3.3'.$parts[2], $json, -1, $count);
if ($count !== 1 || !is_string($newJson)
    || (json_decode($newJson, true)['version'] ?? null) !== '0.3.3') {
    b03cAbort('Connector manifest cannot be safely version-patched.');
}
$replacement['plugin.json'] = $newJson;

echo 'BOTBLE-03C overlay source HEAD: '.$manifest['source_head'].PHP_EOL;
foreach ($replacement as $file => $contents) {
    echo (isset($files[$file]) ? 'replace ' : ($file === $addedFile ? 'add ' : 'surgical update ')).$file.PHP_EOL;
}
echo "ConnectorSigning.php and all existing admin views are unchanged.".PHP_EOL;
echo "No .env, DB, customer products, catalog import switch or media downloads touched.".PHP_EOL;
if ($check) {
    echo "PASS: read-only BOTBLE-03C overlay preflight; no changes made.".PHP_EOL;
    exit(0);
}

// Back up all existing files before making the FIRST change. No backup under public_html.
umask(0077);
$backupRoot = $home.'/datapilot-private-backups';
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
    b03cAbort('Cannot create private backup root.');
}
$backup = $backupRoot.'/botble03c-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (!mkdir($backup, 0700)) {
    b03cAbort('Cannot create private backup.');
}
$before = [];
foreach ($replacement as $file => $contents) {
    $to = $plugin.'/'.$file;
    $before[$file] = is_file($to) ? hash_file('sha256', $to) : null;
    if ($before[$file] === null) {
        continue;
    }
    $dest = $backup.'/'.$file;
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)) {
        b03cAbort('Backup subdirectory creation failed before installation.');
    }
    if (!copy($to, $dest) || hash_file('sha256', $dest) !== $before[$file]) {
        b03cAbort('Backup copy verification failed; no files changed.');
    }
}
file_put_contents($backup.'/ADDED_FILES.txt', $addedFile.PHP_EOL, LOCK_EX);
echo 'Private rollback backup: '.$backup.PHP_EOL;

foreach ($replacement as $file => $contents) {
    $to = $plugin.'/'.$file;
    $actual = is_file($to) ? hash_file('sha256', $to) : null;
    if ($actual !== $before[$file]) {
        b03cAbort('Target changed during installation: '.$file.'; use rollback backup.');
    }
    $temp = tempnam(dirname($to), '.botble03c-');
    if (!$temp || file_put_contents($temp, $contents, LOCK_EX) === false
        || !chmod($temp, 0644) || !rename($temp, $to)) {
        if ($temp) {
            @unlink($temp);
        }
        b03cAbort('Atomic installation failed on '.$file.'; use rollback backup.');
    }
    echo 'Installed '.$file.PHP_EOL;
}
$proc = proc_open([PHP_BINARY, $target.'/artisan', 'optimize:clear'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes, $target);
if (!is_resource($proc)) {
    b03cAbort('Cannot clear Laravel caches; use rollback backup.');
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]);
fwrite(STDERR, stream_get_contents($pipes[2]));
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    b03cAbort('Laravel cache clear failed; use rollback backup.');
}
echo "PASS: Botble Connector 0.3.3 reviewed-inbox overlay installed; v2 flag remains OFF.".PHP_EOL;
