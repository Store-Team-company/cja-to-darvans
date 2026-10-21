<?php
/**
 * Data Pilot - Sync Queue Dashboard + Logs Patch
 *
 * Upload this file to Laravel root and run:
 * php bdp-add-sync-queue-dashboard.php
 * php artisan optimize:clear
 *
 * Adds:
 * - Sync Queue page: /sync-queue
 * - Counts: queued, processing, synced, failed
 * - Queue list with product, status, attempts, error, timestamps
 * - Buttons on Products page: View Sync Queue
 */

$root = getcwd();

function bdp_backup(string $file, string $suffix): void {
    if (is_file($file)) {
        $backup = $file . '.bak-' . $suffix . '-' . date('Ymd-His');
        copy($file, $backup);
        echo "Backup created: {$backup}\n";
    }
}

/**
 * 1) Controller: add syncQueue() page method
 */
$controller = $root . '/app/Http/Controllers/BalencoDataPilotController.php';

if (!is_file($controller)) {
    fwrite(STDERR, "Missing controller: {$controller}\n");
    exit(1);
}

bdp_backup($controller, 'sync-queue-dashboard');

$controllerText = file_get_contents($controller);

if (strpos($controllerText, 'function syncQueue(') === false) {
    $method = <<<'PHP'

    public function syncQueue(\Illuminate\Http\Request $request)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('bdp_product_sync_queue')) {
            return redirect()->route('bdp.products')->with('error', 'Sync queue table is missing. Run php artisan migrate first.');
        }

        $status = $request->input('status', 'all');
        $search = trim((string) $request->input('search', ''));

        $base = \Illuminate\Support\Facades\DB::table('bdp_product_sync_queue as q')
            ->leftJoin('bdp_products as p', 'p.id', '=', 'q.product_id');

        $counts = [
            'all' => (clone $base)->count(),
            'queued' => (clone $base)->where('q.status', 'queued')->count(),
            'processing' => (clone $base)->where('q.status', 'processing')->count(),
            'synced' => (clone $base)->where('q.status', 'synced')->count(),
            'failed' => (clone $base)->where('q.status', 'failed')->count(),
        ];

        $query = \Illuminate\Support\Facades\DB::table('bdp_product_sync_queue as q')
            ->leftJoin('bdp_products as p', 'p.id', '=', 'q.product_id')
            ->select([
                'q.id',
                'q.product_id',
                'q.status',
                'q.attempts',
                'q.last_error',
                'q.queued_at',
                'q.started_at',
                'q.synced_at',
                'q.created_at',
                'q.updated_at',
                'p.name as product_name',
                'p.sku',
                'p.shofy_product_id',
                'p.balenco_sync_status',
                'p.shofy_push_note',
            ]);

        if (in_array($status, ['queued', 'processing', 'synced', 'failed'], true)) {
            $query->where('q.status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', '%' . $search . '%')
                    ->orWhere('p.sku', 'like', '%' . $search . '%')
                    ->orWhere('q.product_id', $search);
            });
        }

        $items = $query
            ->orderByRaw("FIELD(q.status, 'processing', 'queued', 'failed', 'synced')")
            ->orderByDesc('q.updated_at')
            ->paginate((int) $request->input('per_page', 25))
            ->withQueryString();

        return view('balenco-data-pilot.sync-queue', compact('items', 'counts', 'status', 'search'));
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
        fwrite(STDERR, "Controller syntax failed.\n");
        exit(1);
    }

    echo "Controller syncQueue() added.\n";
}

/**
 * 2) Routes: GET /sync-queue
 */
$routes = $root . '/routes/balenco-data-pilot.php';

if (!is_file($routes)) {
    fwrite(STDERR, "Missing route file: {$routes}\n");
    exit(1);
}

bdp_backup($routes, 'sync-queue-dashboard');

$routeText = file_get_contents($routes);

if (strpos($routeText, "sync-queue") === false || strpos($routeText, "sync.queue.index") === false) {
    $routeLine = "    Route::get('/sync-queue', [BalencoDataPilotController::class, 'syncQueue'])->name('sync.queue.index');\n";

    $productsPos = strpos($routeText, "Route::get('/products'");
    if ($productsPos !== false) {
        $lineEnd = strpos($routeText, "\n", $productsPos);
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
        fwrite(STDERR, "Routes syntax failed.\n");
        exit(1);
    }

    echo "Route bdp.sync.queue.index added.\n";
}

/**
 * 3) Create sync queue Blade.
 * Uses the same Data Pilot layout if present. Otherwise it still renders a clean standalone page.
 */
$viewDir = $root . '/resources/views/balenco-data-pilot';
if (!is_dir($viewDir)) {
    mkdir($viewDir, 0755, true);
}

$view = $viewDir . '/sync-queue.blade.php';
bdp_backup($view, 'sync-queue-dashboard');

$blade = <<<'BLADE'
@extends('balenco-data-pilot.layout')

@section('content')
<style>
.bdp-queue-wrap{max-width:1280px;margin:0 auto;padding:8px 0 32px}
.bdp-queue-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.bdp-queue-title h1{font-size:28px;line-height:1.1;margin:0 0 6px;color:#0f172a;font-weight:900}
.bdp-queue-title p{margin:0;color:#64748b;font-size:14px}
.bdp-queue-actions{display:flex;gap:10px;align-items:center}
.bdp-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;background:#fff;color:#0f172a;border-radius:10px;padding:10px 14px;font-weight:800;font-size:13px;text-decoration:none;cursor:pointer}
.bdp-btn.primary{background:#ff5b1a;border-color:#ff5b1a;color:#fff}
.bdp-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:18px}
.bdp-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 10px 30px rgba(15,23,42,.04)}
.bdp-card span{display:block;font-size:12px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.bdp-card strong{display:block;margin-top:8px;font-size:26px;color:#0f172a;font-weight:900}
.bdp-panel{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 12px 34px rgba(15,23,42,.05);overflow:hidden}
.bdp-filter{display:flex;align-items:end;gap:10px;padding:16px;border-bottom:1px solid #edf2f7}
.bdp-field{display:flex;flex-direction:column;gap:6px}
.bdp-field label{font-size:12px;color:#475569;font-weight:800}
.bdp-field input,.bdp-field select{height:40px;border:1px solid #dbe3ef;border-radius:10px;padding:0 12px;background:#fff;min-width:210px}
.bdp-table{width:100%;border-collapse:collapse}
.bdp-table th{background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:12px;border-bottom:1px solid #e5e7eb}
.bdp-table td{padding:13px 12px;border-bottom:1px solid #edf2f7;vertical-align:top;font-size:13px;color:#0f172a}
.bdp-product-name{font-weight:900;color:#0f172a;margin-bottom:4px}
.bdp-muted{color:#64748b;font-size:12px}
.bdp-status{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;text-transform:uppercase}
.bdp-status.queued{background:#eff6ff;color:#1d4ed8}
.bdp-status.processing{background:#fff7ed;color:#c2410c}
.bdp-status.synced{background:#dcfce7;color:#166534}
.bdp-status.failed{background:#fee2e2;color:#b91c1c}
.bdp-error{max-width:420px;color:#b91c1c;font-size:12px;line-height:1.4;white-space:normal}
.bdp-pagination{padding:14px 16px}
@media(max-width:980px){.bdp-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.bdp-filter{flex-wrap:wrap}.bdp-table{min-width:980px}.bdp-panel{overflow-x:auto}}
</style>

<div class="bdp-queue-wrap">
    <div class="bdp-queue-head">
        <div class="bdp-queue-title">
            <h1>Sync Queue</h1>
            <p>Products added here are synced by cron one by one. Variation images are copied to store.balencousa.com during sync.</p>
        </div>
        <div class="bdp-queue-actions">
            <a class="bdp-btn" href="{{ route('bdp.products') }}">Back to Products</a>
        </div>
    </div>

    <div class="bdp-cards">
        <a class="bdp-card" href="{{ route('bdp.sync.queue.index', ['status' => 'all']) }}" style="text-decoration:none">
            <span>All</span>
            <strong>{{ $counts['all'] ?? 0 }}</strong>
        </a>
        <a class="bdp-card" href="{{ route('bdp.sync.queue.index', ['status' => 'queued']) }}" style="text-decoration:none">
            <span>Queued</span>
            <strong>{{ $counts['queued'] ?? 0 }}</strong>
        </a>
        <a class="bdp-card" href="{{ route('bdp.sync.queue.index', ['status' => 'processing']) }}" style="text-decoration:none">
            <span>Processing</span>
            <strong>{{ $counts['processing'] ?? 0 }}</strong>
        </a>
        <a class="bdp-card" href="{{ route('bdp.sync.queue.index', ['status' => 'synced']) }}" style="text-decoration:none">
            <span>Synced</span>
            <strong>{{ $counts['synced'] ?? 0 }}</strong>
        </a>
        <a class="bdp-card" href="{{ route('bdp.sync.queue.index', ['status' => 'failed']) }}" style="text-decoration:none">
            <span>Failed</span>
            <strong>{{ $counts['failed'] ?? 0 }}</strong>
        </a>
    </div>

    <div class="bdp-panel">
        <form method="get" action="{{ route('bdp.sync.queue.index') }}" class="bdp-filter">
            <div class="bdp-field">
                <label>Status</label>
                <select name="status">
                    <option value="all" @selected(($status ?? 'all') === 'all')>All</option>
                    <option value="queued" @selected(($status ?? '') === 'queued')>Queued</option>
                    <option value="processing" @selected(($status ?? '') === 'processing')>Processing</option>
                    <option value="synced" @selected(($status ?? '') === 'synced')>Synced</option>
                    <option value="failed" @selected(($status ?? '') === 'failed')>Failed</option>
                </select>
            </div>
            <div class="bdp-field" style="flex:1">
                <label>Search</label>
                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Product title, SKU, or Data Pilot ID">
            </div>
            <button class="bdp-btn primary" type="submit">Filter</button>
            <a class="bdp-btn" href="{{ route('bdp.sync.queue.index') }}">Reset</a>
        </form>

        <table class="bdp-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Queued</th>
                    <th>Started</th>
                    <th>Synced</th>
                    <th>Last Error / Note</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>
                            <div class="bdp-product-name">{{ $item->product_name ?: 'Product #' . $item->product_id }}</div>
                            <div class="bdp-muted">Data Pilot ID: {{ $item->product_id }} @if($item->shofy_product_id) · Shofy ID: {{ $item->shofy_product_id }} @endif</div>
                        </td>
                        <td>{{ $item->sku ?: '—' }}</td>
                        <td><span class="bdp-status {{ $item->status }}">{{ $item->status }}</span></td>
                        <td>{{ $item->attempts }}</td>
                        <td><span class="bdp-muted">{{ $item->queued_at ?: '—' }}</span></td>
                        <td><span class="bdp-muted">{{ $item->started_at ?: '—' }}</span></td>
                        <td><span class="bdp-muted">{{ $item->synced_at ?: '—' }}</span></td>
                        <td>
                            @if($item->last_error)
                                <div class="bdp-error">{{ $item->last_error }}</div>
                            @else
                                <div class="bdp-muted">{{ \Illuminate\Support\Str::limit((string) $item->shofy_push_note, 160) ?: '—' }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center;padding:30px;color:#64748b">No queue items found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="bdp-pagination">
            {{ $items->links() }}
        </div>
    </div>
</div>
@endsection
BLADE;

file_put_contents($view, $blade);

$check = [];
$return = 0;
exec('php -l ' . escapeshellarg($view) . ' 2>&1', $check, $return);
echo implode("\n", $check) . "\n";

if ($return !== 0) {
    fwrite(STDERR, "Sync queue Blade syntax failed.\n");
    exit(1);
}

echo "Sync queue Blade created.\n";

/**
 * 4) Add View Sync Queue button to products page.
 */
$productsView = $root . '/resources/views/balenco-data-pilot/products.blade.php';

if (is_file($productsView)) {
    bdp_backup($productsView, 'view-sync-queue-button');

    $productsText = file_get_contents($productsView);

    if (strpos($productsText, 'bdp.sync.queue.index') === false) {
        $button = '<a class="bdp-btn light" href="{{ route(\'bdp.sync.queue.index\') }}">View Sync Queue</a>';

        if (strpos($productsText, 'Add to Sync Queue') !== false) {
            $productsText = str_replace('Add to Sync Queue</button>', 'Add to Sync Queue</button>' . "\n                    " . $button, $productsText);
        } elseif (strpos($productsText, 'Sync Selected') !== false) {
            $productsText = preg_replace('/(<button[^>]*>Sync Selected<\/button>)/', '$1' . "\n                    " . $button, $productsText, 1);
        } else {
            echo "Warning: Could not locate sync buttons. View Sync Queue button not inserted.\n";
        }

        file_put_contents($productsView, $productsText);

        $check = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($productsView) . ' 2>&1', $check, $return);
        echo implode("\n", $check) . "\n";

        if ($return !== 0) {
            fwrite(STDERR, "Products Blade syntax failed.\n");
            exit(1);
        }
    }
}

echo "Sync Queue dashboard patch applied.\n";
echo "Next run:\n";
echo "php artisan optimize:clear\n";
echo "Then open: /sync-queue\n";
