<?php
/**
 * Data Pilot - Fix Images ZIP Single Folder
 *
 * Upload this file to Laravel root and run:
 * php bdp-fix-images-zip-single-folder.php
 * php artisan optimize:clear
 *
 * Fix:
 * ZIP should contain only:
 * SKU/
 *   image-1.jpg
 *   image-2.jpg
 *
 * It prevents nested folders inside the SKU folder.
 */

$root = getcwd();
$controller = $root . '/app/Http/Controllers/BalencoDataPilotController.php';

if (!is_file($controller)) {
    fwrite(STDERR, "Missing controller: {$controller}\n");
    exit(1);
}

$backup = $controller . '.bak-zip-single-folder-' . date('Ymd-His');
copy($controller, $backup);
echo "Backup created: {$backup}\n";

$text = file_get_contents($controller);

/**
 * Helper method to make sure every image name inside ZIP is only a filename, not a path.
 */
if (strpos($text, 'private function bdpZipSafeImageFilename(') === false) {
    $helper = <<<'PHP'

    private function bdpZipSafeImageFilename(string $filename): string
    {
        $filename = str_replace(['\\', '/'], '-', $filename);
        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9\.\-_]+/', '-', $filename);
        $filename = preg_replace('/-+/', '-', (string) $filename);
        $filename = trim((string) $filename, '-_.');

        return $filename !== '' ? $filename : 'product-image.jpg';
    }

PHP;

    $pos = strrpos($text, "\n}");
    if ($pos === false) {
        fwrite(STDERR, "Could not find controller closing brace.\n");
        exit(1);
    }

    $text = substr($text, 0, $pos) . $helper . substr($text, $pos);
    echo "Added bdpZipSafeImageFilename() helper.\n";
}

/**
 * Fix all ZIP addFromString calls that use $folder . $filename.
 */
$replacements = 0;

$patterns = [
    '$zip->addFromString($folder . $filename, $content);',
    '$zip->addFromString($folder . $filename, $body);',
    '$zip->addFromString($folder . "/" . $filename, $content);',
    '$zip->addFromString($folder . "/" . $filename, $body);',
];

foreach ($patterns as $pattern) {
    if (strpos($text, $pattern) !== false) {
        $replace = str_contains($pattern, '$body')
            ? '$zip->addFromString(rtrim($folder, \'/\') . \'/\' . $this->bdpZipSafeImageFilename($filename), $body);'
            : '$zip->addFromString(rtrim($folder, \'/\') . \'/\' . $this->bdpZipSafeImageFilename($filename), $content);';

        $text = str_replace($pattern, $replace, $text);
        $replacements++;
    }
}

/**
 * If code has direct dynamic path addFromString, handle safer common variant.
 */
$text = preg_replace(
    '/\$zip->addFromString\(\s*\$folder\s*\.\s*([^,]+?)\s*,\s*\$content\s*\);/',
    '$zip->addFromString(rtrim($folder, \'/\') . \'/\' . $this->bdpZipSafeImageFilename((string) ($1)), $content);',
    $text,
    -1,
    $count1
);
$replacements += $count1;

$text = preg_replace(
    '/\$zip->addFromString\(\s*\$folder\s*\.\s*([^,]+?)\s*,\s*\$body\s*\);/',
    '$zip->addFromString(rtrim($folder, \'/\') . \'/\' . $this->bdpZipSafeImageFilename((string) ($1)), $body);',
    $text,
    -1,
    $count2
);
$replacements += $count2;

/**
 * Normalize folder variable if present.
 */
$text = preg_replace(
    '/\$folder\s*=\s*\$originalSku\s*\.\s*[\'"]\/[\'"]\s*;/',
    '$folder = $this->bdpSafeOriginalSkuForImageZip((string) $originalSku);',
    $text,
    -1,
    $folderCount
);

if ($folderCount > 0) {
    echo "Normalized ZIP folder variable.\n";
}

if ($replacements === 0) {
    echo "Warning: No addFromString folder/filename pattern was replaced. Showing likely ZIP lines:\n";
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'addFromString') !== false || stripos($line, '$folder') !== false) {
            echo ($i + 1) . ': ' . $line . "\n";
        }
    }
}

file_put_contents($controller, $text);

$check = [];
$return = 0;
exec('php -l ' . escapeshellarg($controller) . ' 2>&1', $check, $return);
echo implode("\n", $check) . "\n";

if ($return !== 0) {
    copy($backup, $controller);
    fwrite(STDERR, "Syntax check failed. Restored backup.\n");
    exit(1);
}

echo "Images ZIP single-folder fix applied.\n";
echo "Next run:\n";
echo "php artisan optimize:clear\n";
echo "Then download Images ZIP again.\n";
