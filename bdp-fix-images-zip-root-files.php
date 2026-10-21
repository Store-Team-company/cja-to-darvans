<?php
/**
 * Data Pilot - Images ZIP Root Files Fix
 *
 * Upload this file to Laravel root and run:
 * php bdp-fix-images-zip-root-files.php
 * php artisan optimize:clear
 *
 * Why:
 * Windows/7-Zip creates a folder from the ZIP filename automatically.
 * If the ZIP also contains a SKU folder inside, you get:
 * CJYD2406791/CJYD2406791/images.jpg
 *
 * Fix:
 * ZIP filename stays: CJYD2406791.zip
 * Files inside ZIP are at root:
 *   womens-product-balenco-footwear-b123-featured.jpg
 *   womens-product-balenco-footwear-b123-gallery-01.jpg
 *
 * When extracted normally, Windows creates:
 * CJYD2406791/
 *   womens-product-balenco-footwear-b123-featured.jpg
 */

$root = getcwd();
$controller = $root . '/app/Http/Controllers/BalencoDataPilotController.php';

if (!is_file($controller)) {
    fwrite(STDERR, "Missing controller: {$controller}\n");
    exit(1);
}

$backup = $controller . '.bak-zip-root-files-' . date('Ymd-His');
copy($controller, $backup);
echo "Backup created: {$backup}\n";

$text = file_get_contents($controller);

/**
 * Make sure safe filename helper exists.
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
 * Change ZIP entries from SKU_FOLDER/filename.jpg to filename.jpg at ZIP root.
 * This prevents double SKU folders when users extract the ZIP into a folder.
 */
$replacements = [
    '$zip->addFromString(rtrim($folder, \'/\') . \'/\' . $this->bdpZipSafeImageFilename($filename), $content);'
        => '$zip->addFromString($this->bdpZipSafeImageFilename($filename), $content);',

    '$zip->addFromString(rtrim($folder, \'/\') . \'/\' . $this->bdpZipSafeImageFilename($filename), $body);'
        => '$zip->addFromString($this->bdpZipSafeImageFilename($filename), $body);',

    '$zip->addFromString($folder . $filename, $content);'
        => '$zip->addFromString($this->bdpZipSafeImageFilename($filename), $content);',

    '$zip->addFromString($folder . $filename, $body);'
        => '$zip->addFromString($this->bdpZipSafeImageFilename($filename), $body);',

    '$zip->addFromString($folder . "/" . $filename, $content);'
        => '$zip->addFromString($this->bdpZipSafeImageFilename($filename), $content);',

    '$zip->addFromString($folder . "/" . $filename, $body);'
        => '$zip->addFromString($this->bdpZipSafeImageFilename($filename), $body);',
];

$count = 0;
foreach ($replacements as $from => $to) {
    if (strpos($text, $from) !== false) {
        $text = str_replace($from, $to, $text);
        $count++;
    }
}

/**
 * Handle dynamic variants created by previous patches.
 */
$text = preg_replace(
    '/\$zip->addFromString\(\s*rtrim\(\$folder,\s*[\'"]\/[\'"]\)\s*\.\s*[\'"]\/[\'"]\s*\.\s*\$this->bdpZipSafeImageFilename\((.*?)\)\s*,\s*\$content\s*\);/',
    '$zip->addFromString($this->bdpZipSafeImageFilename($1), $content);',
    $text,
    -1,
    $countA
);
$count += $countA;

$text = preg_replace(
    '/\$zip->addFromString\(\s*rtrim\(\$folder,\s*[\'"]\/[\'"]\)\s*\.\s*[\'"]\/[\'"]\s*\.\s*\$this->bdpZipSafeImageFilename\((.*?)\)\s*,\s*\$body\s*\);/',
    '$zip->addFromString($this->bdpZipSafeImageFilename($1), $body);',
    $text,
    -1,
    $countB
);
$count += $countB;

/**
 * Remove addEmptyDir($folder) if present.
 */
$text = preg_replace('/\s*\$zip->addEmptyDir\(\$folder\);\s*/', "\n", $text, -1, $countC);
$count += $countC;

/**
 * folder variable can remain unused; no issue. But add comment near it if possible.
 */
$text = str_replace(
    '$folder = $this->bdpSafeOriginalSkuForImageZip((string) $originalSku);',
    '$folder = $this->bdpSafeOriginalSkuForImageZip((string) $originalSku); // kept for reference; ZIP entries are root files to avoid double folders on extraction',
    $text
);

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

echo "ZIP image entries changed to root files. Replacements: {$count}\n";
if ($count === 0) {
    echo "Warning: no ZIP addFromString lines were changed. Run this and send output:\n";
    echo "grep -n \"addFromString\\|addEmptyDir\\|bdpZipSafeImageFilename\\|folder =\" app/Http/Controllers/BalencoDataPilotController.php\n";
}
echo "Next run:\n";
echo "php artisan optimize:clear\n";
echo "Then download a fresh Images ZIP and extract again.\n";
