<?php

/**
 * Create a SiteGround upload ZIP from a tested checkout with Composer
 * production dependencies already installed.
 *
 * Usage: php scripts/build-siteground.php
 *
 * Output: dist/data-pilot-siteground.zip
 * Layout after extracting above the domain web root:
 *   data-pilot-app/     <- private Laravel application and vendor/
 *   public_html/        <- index.php, setup.php, public assets
 *   INSTALLATION-CODE.txt
 *   SITEGROUND_INSTALL.md
 */
declare(strict_types=1);

if (!extension_loaded('zip')) {
    fwrite(STDERR, "The zip PHP extension is required to build a package.\n");
    exit(1);
}

$project = dirname(__DIR__);
$distribution = $project.'/dist';
$destination = $distribution.'/data-pilot-siteground.zip';

if (!is_file($project.'/vendor/autoload.php')) {
    fwrite(STDERR, "Install Composer production dependencies before packaging.\n");
    exit(1);
}
if (!is_dir($distribution) && !mkdir($distribution, 0755, true)) {
    throw new RuntimeException('Cannot create distribution folder.');
}

$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create SiteGround ZIP.');
}

function addDirectoryToArchive(ZipArchive $zip, string $source, string $destination): void
{
    if (!is_dir($source)) {
        throw new RuntimeException('Missing package source folder: '.$source);
    }

    $zip->addEmptyDir($destination);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        // Composer packages may ship symlinks; don't package a path that
        // redirects outside the checkout or escapes the private folder.
        if ($item->isLink()) {
            throw new RuntimeException('Unexpected symlink in build input: '.$item->getPathname());
        }

        $relative = substr($item->getPathname(), strlen($source) + 1);
        $archiveName = $destination.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        if ($item->isDir()) {
            $zip->addEmptyDir($archiveName);
        } elseif ($item->isFile()) {
            if (!$zip->addFile($item->getPathname(), $archiveName)) {
                throw new RuntimeException('Could not add file: '.$archiveName);
            }
        }
    }
}

$zip->addEmptyDir('data-pilot-app');
foreach (['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'vendor'] as $directory) {
    addDirectoryToArchive($zip, $project.'/'.$directory, 'data-pilot-app/'.$directory);
}
foreach (['artisan', 'composer.json', 'composer.lock', '.env.example'] as $file) {
    if (!$zip->addFile($project.'/'.$file, 'data-pilot-app/'.$file)) {
        throw new RuntimeException('Could not add application file: '.$file);
    }
}

// Never distribute a local .env, cached Laravel config, Composer auth,
// untracked backups, tests or a previous setup code.
foreach ([
    'data-pilot-app/bootstrap/cache/.gitignore',
    'data-pilot-app/storage/app',
    'data-pilot-app/storage/app/public',
    'data-pilot-app/storage/logs',
    'data-pilot-app/storage/framework',
    'data-pilot-app/storage/framework/cache',
    'data-pilot-app/storage/framework/cache/data',
    'data-pilot-app/storage/framework/sessions',
    'data-pilot-app/storage/framework/testing',
    'data-pilot-app/storage/framework/views',
    'data-pilot-app/bootstrap/cache',
] as $directory) {
    if (str_ends_with($directory, '.gitignore')) {
        continue;
    }
    $zip->addEmptyDir($directory);
}

$zip->addFromString('data-pilot-app/.siteground-layout', "siteground-split-public-private\n");

// The browser installer accepts only the SHA256 of this unique 256-bit code.
// Keep both the code and its hash outside public_html.
$oneTimeCode = bin2hex(random_bytes(32));
$zip->addFromString('data-pilot-app/storage/app/.siteground-install-key', hash('sha256', $oneTimeCode)."\n");
$zip->addFromString(
    'INSTALLATION-CODE.txt',
    "Data Pilot -- one-use SiteGround installation code\n\n".$oneTimeCode.
    "\n\nKeep this secret. Do not upload it to public_html.\nDelete this file after installation.\n"
);

// Only static assets and the special SiteGround front controller are public.
$zip->addEmptyDir('public_html');
$public = $project.'/public';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $item) {
    if ($item->isLink()) {
        throw new RuntimeException('Unexpected symlink in public files: '.$item->getPathname());
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($public) + 1));
    if ($relative === 'index.php' || str_starts_with($relative, 'storage/')) {
        continue;
    }
    $name = 'public_html/'.$relative;
    if ($item->isDir()) {
        $zip->addEmptyDir($name);
    } else {
        $zip->addFile($item->getPathname(), $name);
    }
}

$zip->addFile($project.'/deploy/siteground-index.php', 'public_html/index.php');
$zip->addFile($project.'/docs/SITEGROUND_INSTALL.md', 'SITEGROUND_INSTALL.md');

if (!$zip->close()) {
    throw new RuntimeException('Could not finish the SiteGround ZIP.');
}

$verify = new ZipArchive();
if ($verify->open($destination) !== true) {
    throw new RuntimeException('Cannot verify the resulting SiteGround ZIP.');
}
foreach ([
    'public_html/index.php', 'public_html/setup.php',
    'data-pilot-app/vendor/autoload.php',
    'data-pilot-app/bootstrap/app.php', 'data-pilot-app/.env.example',
    'data-pilot-app/storage/app/.siteground-install-key',
    'INSTALLATION-CODE.txt', 'SITEGROUND_INSTALL.md',
] as $required) {
    if ($verify->locateName($required) === false) {
        throw new RuntimeException('Incomplete SiteGround ZIP: '.$required);
    }
}
foreach (['public_html/.env', 'data-pilot-app/.env', 'public_html/vendor/autoload.php'] as $forbidden) {
    if ($verify->locateName($forbidden) !== false) {
        throw new RuntimeException('Private files unexpectedly exposed: '.$forbidden);
    }
}
echo "Built ".$destination." (".number_format((int) filesize($destination) / 1024 / 1024, 2)." MiB)\n";
echo "Validated application/vendor files, public root and private setup code.\n";
$verify->close();
