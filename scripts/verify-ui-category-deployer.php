<?php
/**
 * CI smoke check for the changes-only SSH UI repair deployer.
 * Validates an authentic SiteGround baseline, dry-run safety, and refusal
 * to overwrite custom files. Does not need customer credentials or hosting.
 */
declare(strict_types=1);

$repo = dirname(__DIR__);
$base = '36dc248fd17e76daca05096ed927ac51cf616e26';
$temp = sys_get_temp_dir().'/datapilot-ui-qa-'.bin2hex(random_bytes(6));
$target = $temp.'/www/example.test/data-pilot-app';
$public = dirname($target).'/public_html';
$oldFiles = [
    'app/Http/Controllers/BalencoDataPilotController.php',
    'app/Http/Controllers/BdpCategoryMappingController.php',
    'app/Services/BdpShofyCategoryService.php',
    'resources/views/balenco-data-pilot/layout.blade.php',
    'resources/views/balenco-data-pilot/dashboard.blade.php',
    'resources/views/balenco-data-pilot/sync-queue.blade.php',
    'resources/views/balenco-data-pilot/category-mapping.blade.php',
];

function assertRepair(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('UI repair deployer smoke test failed: '.$message);
    }
}
function runGitRead(string $repo, array $arguments): string
{
    $process = proc_open(
        array_merge(['git'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $repo
    );
    assertRepair(is_resource($process), 'git process missing');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertRepair(proc_close($process) === 0, 'git show failed: '.$stderr);
    return (string) $stdout;
}
function executeRepair(string $repo, array $arguments): array
{
    $script = $repo.'/scripts/deploy-siteground-ui-category-repair.php';
    $process = proc_open(array_merge([PHP_BINARY, $script], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
    assertRepair(is_resource($process), 'Could not spawn updater');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out];
}

assertRepair(mkdir($target, 0700, true), 'target folder missing');
assertRepair(mkdir($public, 0700, true), 'public folder missing');
assertRepair(mkdir($target.'/vendor', 0700, true), 'vendor folder missing');
file_put_contents($target.'/.env', 'APP_KEY=TEST_KEY_NEVER_DEPLOY');
file_put_contents($target.'/.siteground-layout', 'siteground-layout');
file_put_contents($target.'/artisan', "<?php\n");
file_put_contents($target.'/vendor/autoload.php', "<?php\n");

foreach ($oldFiles as $path) {
    $local = $target.'/'.$path;
    assertRepair(is_dir(dirname($local)) || mkdir(dirname($local), 0700, true),
        'cannot create baseline directory');
    file_put_contents($local, runGitRead($repo, ['show', $base.':'.$path]));
}

[$exit, $output] = executeRepair($repo, ['--check', '--target='.$target]);
assertRepair($exit === 0 && str_contains($output, 'Preflight PASS: 7 files checked'),
    'official SiteGround baseline rejected: '.$output);
assertRepair(!is_dir(dirname($target).'/datapilot-private-backups'),
    'dry run unexpectedly created backup');

[$exit, $output] = executeRepair($repo, ['--apply', '--target='.$target]);
assertRepair($exit !== 0 && str_contains($output, '--backup-confirmed'),
    'update applied without backup acknowledgement');

file_put_contents($target.'/app/Services/BdpShofyCategoryService.php', "\n// user customized\n", FILE_APPEND);
[$exit, $output] = executeRepair($repo, ['--check', '--target='.$target]);
assertRepair($exit !== 0 && str_contains($output, 'differs from both supported release baselines'),
    'modified production source was not rejected');
assertRepair(!is_dir(dirname($target).'/datapilot-private-backups'),
    'rejected update created backup');

echo "PASS: SiteGround UI category updater supports original release, blocks unreviewed edits, dry-run is read-only, backup check enforced.\n";
