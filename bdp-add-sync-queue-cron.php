<?php
/**
 * Data Pilot - Sync Queue + Cron Patch
 *
 * Upload this file to Laravel root and run:
 * php bdp-add-sync-queue-cron.php
 * php artisan migrate
 * php artisan optimize:clear
 *
 * Test queue command:
 * php artisan bdp:sync-queued-products --limit=1
 *
 * Cron:
 * * * * * cd /home/1556810.cloudwaysapps.com/rgxpgjudaw/public_html && php artisan bdp:sync-queued-products --limit=1 >> storage/logs/bdp-sync-queue.log 2>&1
 */

$root = getcwd();

function bdp_backup_file(string $file, string $suffix): void {
    if (is_file($file)) {
        $backup = $file . '.bak-' . $suffix . '-' . date('Ymd-His');
        copy($file, $backup);
        echo "Backup created: {$backup}\n";
    }
}

/**
 * 1) Migration
 */
$migrationDir = $root . '/database/migrations';
if (!is_dir($migrationDir)) {
    mkdir($migrationDir, 0755, true);
}

$migrationFile = $migrationDir . '/' . date('Y_m_d_His') . '_create_bdp_product_sync_queue_table.php';

$migrationCode = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bdp_product_sync_queue')) {
            Schema::create('bdp_product_sync_queue', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->unique();
                $table->string('status', 30)->default('queued')->index(); // queued, processing, synced, failed
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('queued_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'queued_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_product_sync_queue');
    }
};
PHP;

file_put_contents($migrationFile, $migrationCode);
echo "Migration created: {$migrationFile}\n";

/**
 * 2) Artisan command
 */
$commandDir = $root . '/app/Console/Commands';
if (!is_dir($commandDir)) {
    mkdir($commandDir, 0755, true);
}

$commandFile = $commandDir . '/BdpSyncQueuedProducts.php';
bdp_backup_file($commandFile, 'sync-queue-command');

$commandCode = <<<'PHP'
<?php

namespace App\Console\Commands;

use App\Services\BdpShofyDirectPusher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BdpSyncQueuedProducts extends Command
{
    protected $signature = 'bdp:sync-queued-products
        {--limit=1 : Number of queued products to sync per run}
        {--sleep=0 : Seconds to wait between products if limit is more than 1}
        {--retry-failed : Include failed items for retry}
        {--max-attempts=3 : Maximum retry attempts for failed products}';

    protected $description = 'Sync queued Data Pilot products to the connected Shofy/Botble store, safely by cron.';

    public function handle(BdpShofyDirectPusher $pusher): int
    {
        $limit = max(1, min(20, (int) $this->option('limit')));
        $sleep = max(0, min(30, (int) $this->option('sleep')));
        $retryFailed = (bool) $this->option('retry-failed');
        $maxAttempts = max(1, min(10, (int) $this->option('max-attempts')));

        $processed = 0;
        $synced = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $item = DB::transaction(function () use ($retryFailed, $maxAttempts) {
                // Release stuck processing items older than 30 minutes.
                DB::table('bdp_product_sync_queue')
                    ->where('status', 'processing')
                    ->whereNotNull('started_at')
                    ->where('started_at', '<', now()->subMinutes(30))
                    ->update([
                        'status' => 'queued',
                        'last_error' => 'Processing lock expired and item was returned to queue.',
                        'updated_at' => now(),
                    ]);

                $query = DB::table('bdp_product_sync_queue')
                    ->where(function ($q) use ($retryFailed, $maxAttempts) {
                        $q->where('status', 'queued');

                        if ($retryFailed) {
                            $q->orWhere(function ($failedQuery) use ($maxAttempts) {
                                $failedQuery->where('status', 'failed')
                                    ->where('attempts', '<', $maxAttempts);
                            });
                        }
                    })
                    ->orderBy('queued_at')
                    ->orderBy('id')
                    ->lockForUpdate();

                $row = $query->first();

                if (!$row) {
                    return null;
                }

                DB::table('bdp_product_sync_queue')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 'processing',
                        'started_at' => now(),
                        'attempts' => ((int) $row->attempts) + 1,
                        'updated_at' => now(),
                    ]);

                return DB::table('bdp_product_sync_queue')->where('id', $row->id)->first();
            });

            if (!$item) {
                if ($processed === 0) {
                    $this->info('No queued products found.');
                }
                break;
            }

            $processed++;
            $productId = (int) $item->product_id;

            try {
                $this->line("Syncing queued product #{$productId}");

                $result = $pusher->push([$productId], [
                    'copy_variation_images' => true,
                    'sync_status' => 'draft',
                ]);

                if (!empty($result['errors'])) {
                    throw new \RuntimeException(implode(' | ', array_slice($result['errors'], 0, 5)));
                }

                DB::table('bdp_product_sync_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 'synced',
                        'last_error' => null,
                        'synced_at' => now(),
                        'updated_at' => now(),
                    ]);

                $synced++;
                $this->info("Synced queued product #{$productId}");
            } catch (Throwable $e) {
                DB::table('bdp_product_sync_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 'failed',
                        'last_error' => mb_substr($e->getMessage(), 0, 5000),
                        'updated_at' => now(),
                    ]);

                $failed++;
                $this->error("Failed product #{$productId}: {$e->getMessage()}");
            }

            if ($sleep > 0 && $i < $limit - 1) {
                sleep($sleep);
            }
        }

        $this->info("Done. Processed {$processed}. Synced {$synced}. Failed {$failed}.");

        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }
}
PHP;

file_put_contents($commandFile, $commandCode);

$check = [];
$return = 0;
exec('php -l ' . escapeshellarg($commandFile) . ' 2>&1', $check, $return);
echo implode("\n", $check) . "\n";

if ($return !== 0) {
    fwrite(STDERR, "Command syntax check failed.\n");
    exit(1);
}

/**
 * 3) Register command in Console Kernel if needed.
 */
$kernel = $root . '/app/Console/Kernel.php';
if (is_file($kernel)) {
    bdp_backup_file($kernel, 'sync-queue-kernel');

    $kernelText = file_get_contents($kernel);

    // Remove misplaced duplicate import if any.
    $kernelText = preg_replace('/^\s*use\s+App\\\\Console\\\\Commands\\\\BdpSyncQueuedProducts;\s*$/m', '', $kernelText);

    if (strpos($kernelText, 'namespace App\\Console;') !== false && strpos($kernelText, 'BdpSyncQueuedProducts::class') === false) {
        $kernelText = preg_replace(
            '/namespace\s+App\\\\Console;\s*/',
            "namespace App\\Console;\n\nuse App\\Console\\Commands\\BdpSyncQueuedProducts;\n",
            $kernelText,
            1
        );

        if (preg_match('/protected\s+\$commands\s*=\s*\[/s', $kernelText)) {
            $kernelText = preg_replace(
                '/protected\s+\$commands\s*=\s*\[\s*/s',
                "protected \$commands = [\n        BdpSyncQueuedProducts::class,\n        ",
                $kernelText,
                1
            );
        } else {
            $kernelText = preg_replace(
                '/class\s+Kernel\s+extends\s+ConsoleKernel\s*\{/s',
                "class Kernel extends ConsoleKernel\n{\n    protected \$commands = [\n        BdpSyncQueuedProducts::class,\n    ];\n",
                $kernelText,
                1
            );
        }

        file_put_contents($kernel, $kernelText);

        $check = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($kernel) . ' 2>&1', $check, $return);
        echo implode("\n", $check) . "\n";

        if ($return !== 0) {
            fwrite(STDERR, "Kernel syntax check failed.\n");
            exit(1);
        }

        echo "Command registered in Console Kernel.\n";
    }
}

/**
 * 4) Controller method
 */
$controller = $root . '/app/Http/Controllers/BalencoDataPilotController.php';
if (!is_file($controller)) {
    fwrite(STDERR, "Missing controller: {$controller}\n");
    exit(1);
}

bdp_backup_file($controller, 'sync-queue-controller');

$controllerText = file_get_contents($controller);

if (strpos($controllerText, 'function enqueueSyncQueue') === false) {
    $method = <<<'PHP'

    public function enqueueSyncQueue(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'products' => ['required', 'array'],
            'products.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['products'] ?? [])));

        if (!$ids) {
            return back()->with('error', 'Please select at least one product to add to sync queue.');
        }

        $existingIds = \App\Models\BdpProduct::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!$existingIds) {
            return back()->with('error', 'No valid products were found for sync queue.');
        }

        $added = 0;
        $reactivated = 0;
        $alreadyQueued = 0;

        foreach ($existingIds as $productId) {
            $existing = \Illuminate\Support\Facades\DB::table('bdp_product_sync_queue')
                ->where('product_id', $productId)
                ->first();

            if (!$existing) {
                \Illuminate\Support\Facades\DB::table('bdp_product_sync_queue')->insert([
                    'product_id' => $productId,
                    'status' => 'queued',
                    'attempts' => 0,
                    'last_error' => null,
                    'queued_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added++;
                continue;
            }

            if (in_array($existing->status, ['synced', 'failed'], true)) {
                \Illuminate\Support\Facades\DB::table('bdp_product_sync_queue')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => 'queued',
                        'last_error' => null,
                        'queued_at' => now(),
                        'updated_at' => now(),
                    ]);
                $reactivated++;
                continue;
            }

            $alreadyQueued++;
        }

        return back()->with('success', "Sync queue updated. Added {$added}, requeued {$reactivated}, already queued/processing {$alreadyQueued}.");
    }

PHP;

    $pos = strrpos($controllerText, "\n}");
    if ($pos === false) {
        fwrite(STDERR, "Could not find controller closing brace.\n");
        exit(1);
    }

    $controllerText = substr($controllerText, 0, $pos) . $method . substr($controllerText, $pos);
    file_put_contents($controller, $controllerText);

    $check = [];
    $return = 0;
    exec('php -l ' . escapeshellarg($controller) . ' 2>&1', $check, $return);
    echo implode("\n", $check) . "\n";

    if ($return !== 0) {
        fwrite(STDERR, "Controller syntax check failed.\n");
        exit(1);
    }

    echo "Controller enqueueSyncQueue() added.\n";
}

/**
 * 5) Route
 */
$routes = $root . '/routes/balenco-data-pilot.php';
if (!is_file($routes)) {
    fwrite(STDERR, "Missing route file: {$routes}\n");
    exit(1);
}

bdp_backup_file($routes, 'sync-queue-route');

$routeText = file_get_contents($routes);

if (strpos($routeText, 'sync.queue.enqueue') === false) {
    $routeLine = "    Route::post('/sync-queue/enqueue', [BalencoDataPilotController::class, 'enqueueSyncQueue'])->name('sync.queue.enqueue');\n";

    $pushRoutePos = strpos($routeText, "Route::post('/push-to-shofy'");
    if ($pushRoutePos !== false) {
        $lineEnd = strpos($routeText, "\n", $pushRoutePos);
        $routeText = substr($routeText, 0, $lineEnd + 1) . $routeLine . substr($routeText, $lineEnd + 1);
    } else {
        $routeText = preg_replace('/\}\);?\s*$/', $routeLine . "});\n", $routeText, 1);
    }

    file_put_contents($routes, $routeText);

    $check = [];
    $return = 0;
    exec('php -l ' . escapeshellarg($routes) . ' 2>&1', $check, $return);
    echo implode("\n", $check) . "\n";

    if ($return !== 0) {
        fwrite(STDERR, "Routes syntax check failed.\n");
        exit(1);
    }

    echo "Sync queue route added.\n";
}

/**
 * 6) Products Blade button
 */
$view = $root . '/resources/views/balenco-data-pilot/products.blade.php';
if (is_file($view)) {
    bdp_backup_file($view, 'sync-queue-button');

    $viewText = file_get_contents($view);

    if (strpos($viewText, 'sync.queue.enqueue') === false) {
        $button = '<button class="bdp-btn light" type="submit" form="bdp-products-form" formaction="{{ route(\'bdp.sync.queue.enqueue\') }}" formmethod="post">Add to Sync Queue</button>';

        $patterns = [
            '/(<button[^>]*formaction="\{\{\s*route\(\'bdp\.push\'\)\s*\}\}"[^>]*>Sync Selected<\/button>)/',
            '/(<button[^>]*>Sync Selected<\/button>)/',
        ];

        $inserted = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $viewText)) {
                $viewText = preg_replace($pattern, '$1' . "\n                    " . $button, $viewText, 1);
                $inserted = true;
                echo "Add to Sync Queue button inserted after Sync Selected.\n";
                break;
            }
        }

        if (!$inserted) {
            echo "Warning: Could not find Sync Selected button. Add this button manually inside the products form bulk action bar:\n";
            echo $button . "\n";
        }

        file_put_contents($view, $viewText);

        $check = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($view) . ' 2>&1', $check, $return);
        echo implode("\n", $check) . "\n";

        if ($return !== 0) {
            fwrite(STDERR, "Products view syntax check failed.\n");
            exit(1);
        }
    }
}

echo "Sync queue patch applied.\n";
echo "Next:\n";
echo "php artisan migrate\n";
echo "php artisan optimize:clear\n";
echo "php artisan list | grep \"bdp:sync-queued-products\"\n";
echo "php artisan bdp:sync-queued-products --limit=1\n";
