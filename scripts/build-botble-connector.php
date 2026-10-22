<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}
$root = dirname(__DIR__);
$plugin = $root.'/integrations/botble-connector';
$manifest = json_decode((string) file_get_contents($plugin.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['version'] ?? '') !== '0.3.0'
    || ($manifest['namespace'] ?? '') !== 'Botble\\DatapilotConnector\\'
    || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ABORT: Invalid Botble connector manifest or ZipArchive unavailable.\n");
    exit(2);
}
$outdir = $root.'/dist';
if (!is_dir($outdir) && !mkdir($outdir, 0755, true)) {
    throw new RuntimeException('Could not create build output.');
}
$dest = $outdir.'/datapilot-connector-0.3.0.zip';
$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot build Botble plugin ZIP.');
}
$sourceRoot = 'datapilot-connector/';
$zip->addEmptyDir($sourceRoot);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugin, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$count = 0;
foreach ($iterator as $file) {
    if ($file->isLink()) {
        throw new RuntimeException('Symlink in plugin package: '.$file->getPathname());
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($plugin) + 1));
    if (str_contains($relative, '..') || str_contains($relative, '/.env')
        || preg_match('/(^|\/)(\.env|vendor|\.git|node_modules)(\/|$)/', $relative)) {
        throw new RuntimeException('Unsafe plugin path: '.$relative);
    }
    if ($file->isDir()) {
        $zip->addEmptyDir($sourceRoot.$relative);
    } elseif ($file->isFile()) {
        if (!preg_match('/\.(php|json|md)$|\.blade\.php$/D', $relative)) {
            throw new RuntimeException('Unexpected packaged extension: '.$relative);
        }
        if (!$zip->addFile($file->getPathname(), $sourceRoot.$relative)) {
            throw new RuntimeException('Could not add '.$relative);
        }
        $count++;
    }
}
if ($count < 10 || !$zip->close()) {
    throw new RuntimeException('Plugin package incomplete.');
}
$verify = new ZipArchive();
if ($verify->open($dest) !== true ||
    $verify->locateName('datapilot-connector/plugin.json') === false ||
    $verify->locateName('datapilot-connector/src/Providers/DatapilotConnectorServiceProvider.php') === false ||
    $verify->locateName('datapilot-connector/database/migrations/2026_10_22_000001_create_datapilot_connector_tables.php') === false ||
    $verify->locateName('datapilot-connector/src/Services/CatalogImportService.php') === false ||
    $verify->locateName('datapilot-connector/database/migrations/2026_10_22_000002_create_datapilot_product_mappings.php') === false) {
    throw new RuntimeException('Package verification failed.');
}
$verify->close();
echo "PASS: installable Botble plugin ZIP: {$dest} ({$count} files)\n";
