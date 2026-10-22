<?php
/**
 * Creates a minimal, preflight-verified update for EXISTING SiteGround
 * installations. Intentionally excludes the nonfunctional Botble scaffold,
 * existing products, .env, vendor, public_html, and legacy importer files.
 *
 * Usage: php scripts/build-siteground-saas-update.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$baseCommit = 'a14a6772c1483989c8f90aecc1a7b268cbe28d35';
$output = $root.'/dist/data-pilot-saas-changes-only.zip';

$add = [
    'app/Http/Controllers/Saas/SaasAuthController.php',
    'app/Http/Controllers/Saas/WorkspaceDashboardController.php',
    'app/Http/Middleware/EnsureSaasEnabled.php',
    'app/Http/Middleware/EnsureSaasWorkspaceMember.php',
    'app/Models/SaasBotbleStore.php',
    'app/Models/SaasCjConnection.php',
    'app/Models/SaasProduct.php',
    'app/Models/SaasWorkspace.php',
    'app/Services/Saas/WorkspacePermissions.php',
    'config/saas.php',
    'database/migrations/2026_10_22_000001_create_saas_workspaces_and_connections.php',
    'resources/views/saas/auth/login.blade.php',
    'resources/views/saas/auth/register.blade.php',
    'resources/views/saas/auth/verify-email.blade.php',
    'resources/views/saas/dashboard.blade.php',
    'routes/saas.php',
];
$replace = [
    'app/Http/Middleware/Authenticate.php',
    'app/Models/User.php',
    'routes/web.php',
];

if (!extension_loaded('zip')) {
    throw new RuntimeException('PHP zip extension is required.');
}

function fromBase(string $root, string $commit, string $path): string
{
    $process = proc_open(
        ['git', 'show', $commit.':'.$path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('git show is unavailable.');
    }
    fclose($pipes[0]);
    $content = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($content)) {
        throw new RuntimeException('Cannot read base file '.$path.': '.$error);
    }
    return $content;
}

if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true)) {
    throw new RuntimeException('Cannot create dist folder.');
}
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create update ZIP.');
}
$manifest = [
    'version' => 1,
    'name' => 'Data Pilot SaaS customer workspace foundation (NOT Stripe or Botble publishing)',
    'expected_base_commit' => $baseCommit,
    'saas_disabled_by_default' => true,
    'files' => [],
];

foreach (['add' => $add, 'replace' => $replace] as $mode => $paths) {
    foreach ($paths as $path) {
        $absolute = $root.'/'.$path;
        if (!is_file($absolute) || is_link($absolute)) {
            throw new RuntimeException('Missing/unsafe file: '.$path);
        }
        $content = (string) file_get_contents($absolute);
        $entry = [
            'path' => $path,
            'mode' => $mode,
            'sha256' => hash('sha256', $content),
        ];
        if ($mode === 'replace') {
            $entry['original_sha256'] = hash('sha256', fromBase($root, $baseCommit, $path));
        }
        $manifest['files'][] = $entry;
        if (!$zip->addFromString('payload/data-pilot-app/'.$path, $content)) {
            throw new RuntimeException('Unable to add file '.$path);
        }
    }
}
$manifestText = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$zip->addFromString('update-manifest.json', $manifestText);
foreach ([
    'scripts/apply-siteground-saas-update.php' => 'APPLY-SaaS-UPDATE.php',
    'docs/SITEGROUND_SAAS_INCREMENTAL_UPDATE.md' => 'SITEGROUND_SAAS_INCREMENTAL_UPDATE.md',
] as $source => $destination) {
    if (!$zip->addFile($root.'/'.$source, $destination)) {
        throw new RuntimeException('Missing deployment helper: '.$source);
    }
}
if (!$zip->close()) {
    throw new RuntimeException('Could not finish update archive.');
}

// Inspect the actual archive; no private operational state may be bundled.
$check = new ZipArchive();
if ($check->open($output) !== true) {
    throw new RuntimeException('Cannot verify generated ZIP.');
}
$expected = array_merge(
    array_map(fn ($entry) => 'payload/data-pilot-app/'.$entry['path'], $manifest['files']),
    ['update-manifest.json', 'APPLY-SaaS-UPDATE.php', 'SITEGROUND_SAAS_INCREMENTAL_UPDATE.md']
);
if ($check->numFiles !== count($expected)) {
    throw new RuntimeException('Unexpected number of ZIP entries.');
}
foreach ($expected as $path) {
    if ($check->locateName($path) === false) {
        throw new RuntimeException('Missing expected ZIP entry: '.$path);
    }
}
foreach (['.env', 'vendor/', 'public_html/', 'storage/', 'integrations/'] as $forbidden) {
    foreach ($expected as $path) {
        if (str_contains($path, $forbidden)) {
            throw new RuntimeException('Forbidden update path: '.$path);
        }
    }
}
$check->close();
echo 'PASS: '.$output.' ('.number_format(filesize($output) / 1024, 1)." KiB)\n";
echo count($add)." new files, ".count($replace)." controlled replacements, only new saas_* tables.\n";
echo "No database data, installer, secrets, Botble publishing code, or app runtime dependencies included.\n";
