<?php

/**
 * Update-only ZIP for an EXISTING SiteGround Data Pilot installation.
 *
 * This ZIP only contains the reviewed controller and three Blade views.
 * No Laravel .env, vendor, database migrations, app key, storage,
 * image uploads, installer, or installation code is included.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$destination = $root.'/dist/data-pilot-siteground-ui-update.zip';

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}
if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
    throw new RuntimeException('Cannot create dist folder.');
}

$paths = [
    'app/Http/Controllers/BalencoDataPilotController.php',
    'resources/views/balenco-data-pilot/layout.blade.php',
    'resources/views/balenco-data-pilot/dashboard.blade.php',
    'resources/views/balenco-data-pilot/sync-queue.blade.php',
];

$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create update ZIP.');
}

foreach ($paths as $path) {
    $full = $root.'/'.$path;
    if (!is_file($full) || is_link($full)) {
        throw new RuntimeException('Missing or unsafe update file: '.$path);
    }
    if (!$zip->addFile($full, 'data-pilot-app/'.$path)) {
        throw new RuntimeException('Unable to package: '.$path);
    }
}

if (!$zip->addFile($root.'/docs/SITEGROUND_UI_UPDATE.md', 'SITEGROUND_UI_UPDATE.md')) {
    throw new RuntimeException('Missing update instructions.');
}
if (!$zip->close()) {
    throw new RuntimeException('Failed to finish update ZIP.');
}

$verify = new ZipArchive();
if ($verify->open($destination) !== true) {
    throw new RuntimeException('Failed to verify update ZIP.');
}

$expected = array_merge(array_map(fn ($p) => 'data-pilot-app/'.$p, $paths), ['SITEGROUND_UI_UPDATE.md']);
for ($i = 0; $i < $verify->numFiles; $i++) {
    $actual = $verify->getNameIndex($i);
    if (!in_array($actual, $expected, true)) {
        throw new RuntimeException('Unapproved update ZIP entry: '.$actual);
    }
}
foreach ($expected as $path) {
    if ($verify->locateName($path) === false) {
        throw new RuntimeException('Missing update ZIP entry: '.$path);
    }
}
$verify->close();

echo 'Built '.$destination.' ('.number_format((int) filesize($destination) / 1024, 1)." KiB)\n";
echo "Verified: 4 source files + update guide. No .env, vendor, installer, database or storage files.\n";
