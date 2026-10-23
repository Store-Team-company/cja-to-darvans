<?php

/** Package the narrow 0.3.2 -> 0.3.3 runtime overlay; never ship .env or secrets. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !class_exists(ZipArchive::class)) {
    exit(2);
}
$root = dirname(__DIR__);
$files = [
    'src/Http/Controllers/DraftInboxController.php',
    'src/Services/CatalogImportService.php',
    'src/Services/SignedDraftHtmlSanitizer.php',
];
$manifest = [
    'source_head' => '',
    'target_version' => '0.3.3',
    'payload_hashes' => [],
];
$git = proc_open(['git', 'rev-parse', 'HEAD'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes, $root);
if (!is_resource($git)) {
    throw new RuntimeException('Git commit verification unavailable.');
}
fclose($pipes[0]);
$sha = trim(stream_get_contents($pipes[1]));
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($git) !== 0 || !preg_match('/^[a-f0-9]{40}$/D', $sha)) {
    throw new RuntimeException('Invalid release source HEAD.');
}
$manifest['source_head'] = $sha;
foreach ($files as $path) {
    $source = $root.'/integrations/botble-connector/'.$path;
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException('Missing/unsafe overlay payload: '.$path);
    }
    $manifest['payload_hashes'][$path] = hash_file('sha256', $source);
}
$script = $root.'/scripts/deploy-botble-03c-overlay.php';
if (!is_file($script) || is_link($script)) {
    throw new RuntimeException('Missing/unsafe overlay installer.');
}
$outdir = $root.'/dist';
if (!is_dir($outdir) && !mkdir($outdir, 0755, true)) {
    throw new RuntimeException('Cannot create artifact directory.');
}
$archive = $outdir.'/datapilot-botble-03c-overlay.zip';
$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot write overlay archive.');
}
$prefix = 'botble03c-overlay/';
$zip->addFile($script, $prefix.'installer.php');
$zip->addFromString($prefix.'botble03c-manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
foreach ($files as $path) {
    $zip->addFile($root.'/integrations/botble-connector/'.$path, $prefix.'payload/'.$path);
}
if (!$zip->close()) {
    throw new RuntimeException('Overlay ZIP is incomplete.');
}
$check = new ZipArchive();
if ($check->open($archive) !== true || $check->numFiles !== 5) {
    throw new RuntimeException('Unexpected overlay ZIP entry count.');
}
$extracted = json_decode((string) $check->getFromName($prefix.'botble03c-manifest.json'), true);
if (($extracted['source_head'] ?? null) !== $sha
    || $check->locateName($prefix.'installer.php') === false) {
    throw new RuntimeException('Overlay ZIP manifest verification failed.');
}
foreach ($files as $path) {
    $body = $check->getFromName($prefix.'payload/'.$path);
    if (!is_string($body) || hash('sha256', $body) !== $manifest['payload_hashes'][$path]) {
        throw new RuntimeException('Overlay ZIP content mismatch: '.$path);
    }
}
$check->close();
echo "PASS: private Botble 0.3.2 -> 0.3.3 overlay built from commit ".$sha.PHP_EOL;
echo 'Package: '.$archive.PHP_EOL;
