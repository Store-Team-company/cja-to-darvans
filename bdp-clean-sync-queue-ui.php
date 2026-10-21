<?php
/**
 * Data Pilot - Clean Sync Queue UI Patch
 *
 * Upload this file to Laravel root and run:
 * php bdp-clean-sync-queue-ui.php
 * php artisan optimize:clear
 *
 * Fixes:
 * - Removes huge broken pagination arrows
 * - Makes Sync Queue page compact and production-ready
 * - Keeps queue/status/filter logic unchanged
 */

$root = getcwd();
$view = $root . '/resources/views/balenco-data-pilot/sync-queue.blade.php';

if (!is_file($view)) {
    fwrite(STDERR, "Missing file: {$view}\n");
    exit(1);
}

$backup = $view . '.bak-clean-sync-queue-ui-' . date('Ymd-His');
copy($view, $backup);
echo "Backup created: {$backup}\n";

$blade = <<<'BLADE'
@extends('balenco-data-pilot.layout')

@section('content')
<style>
.bdp-sync-page {
    max-width: 1320px;
    margin: 0 auto;
    padding: 8px 0 40px;
}
.bdp-sync-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 18px;
    margin-bottom: 18px;
}
.bdp-sync-title h1 {
    margin: 0 0 6px;
    font-size: 28px;
    line-height: 1.15;
    color: #0f172a;
    font-weight: 900;
    letter-spacing: -0.03em;
}
.bdp-sync-title p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}
.bdp-sync-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    padding: 0 15px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #0f172a;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}
.bdp-sync-btn:hover {
    background: #f8fafc;
}
.bdp-sync-btn.primary {
    background: #ff5b1a;
    border-color: #ff5b1a;
    color: #fff;
}
.bdp-sync-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}
.bdp-sync-stat {
    display: block;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 15px 16px;
    text-decoration: none;
    box-shadow: 0 8px 26px rgba(15, 23, 42, .04);
}
.bdp-sync-stat span {
    display: block;
    margin-bottom: 9px;
    color: #64748b;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.bdp-sync-stat strong {
    display: block;
    color: #0f172a;
    font-size: 25px;
    line-height: 1;
    font-weight: 900;
}
.bdp-sync-stat.active {
    border-color: #ff5b1a;
    box-shadow: 0 12px 30px rgba(255, 91, 26, .10);
}
.bdp-sync-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 12px 36px rgba(15, 23, 42, .05);
}
.bdp-sync-filter {
    display: grid;
    grid-template-columns: 210px 1fr auto auto;
    gap: 10px;
    align-items: end;
    padding: 16px;
    border-bottom: 1px solid #edf2f7;
}
.bdp-sync-field label {
    display: block;
    margin-bottom: 6px;
    color: #475569;
    font-size: 12px;
    font-weight: 900;
}
.bdp-sync-field input,
.bdp-sync-field select {
    width: 100%;
    height: 40px;
    border: 1px solid #dbe3ef;
    border-radius: 10px;
    background: #fff;
    padding: 0 12px;
    color: #0f172a;
    font-size: 13px;
}
.bdp-sync-table-wrap {
    overflow-x: auto;
}
.bdp-sync-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.bdp-sync-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .04em;
    text-align: left;
    padding: 12px 12px;
    border-bottom: 1px solid #e5e7eb;
}
.bdp-sync-table td {
    padding: 12px;
    border-bottom: 1px solid #edf2f7;
    vertical-align: top;
    font-size: 13px;
    color: #0f172a;
}
.bdp-sync-table tr:hover td {
    background: #fbfdff;
}
.bdp-col-product { width: 28%; }
.bdp-col-sku { width: 12%; }
.bdp-col-status { width: 10%; }
.bdp-col-attempts { width: 8%; }
.bdp-col-date { width: 11%; }
.bdp-col-note { width: 20%; }
.bdp-product-name {
    margin-bottom: 4px;
    color: #0f172a;
    font-weight: 900;
    line-height: 1.3;
}
.bdp-muted {
    color: #64748b;
    font-size: 12px;
    line-height: 1.35;
}
.bdp-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 74px;
    height: 24px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.bdp-status.queued { background: #eff6ff; color: #1d4ed8; }
.bdp-status.processing { background: #fff7ed; color: #c2410c; }
.bdp-status.synced { background: #dcfce7; color: #166534; }
.bdp-status.failed { background: #fee2e2; color: #b91c1c; }
.bdp-note {
    max-width: 100%;
    color: #64748b;
    font-size: 12px;
    line-height: 1.45;
    word-break: break-word;
}
.bdp-note.error {
    color: #b91c1c;
}
.bdp-sync-empty {
    padding: 36px;
    text-align: center;
    color: #64748b;
}
.bdp-sync-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: #fff;
}
.bdp-sync-pagination-info {
    color: #64748b;
    font-size: 12px;
}
.bdp-sync-pages {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.bdp-sync-page-link,
.bdp-sync-page-current,
.bdp-sync-page-disabled {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 9px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #0f172a;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
}
.bdp-sync-page-current {
    background: #0f172a;
    border-color: #0f172a;
    color: #fff;
}
.bdp-sync-page-disabled {
    color: #cbd5e1;
    background: #f8fafc;
}
@media (max-width: 1100px) {
    .bdp-sync-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .bdp-sync-filter { grid-template-columns: 1fr; }
    .bdp-sync-table { min-width: 1050px; }
}
</style>

<div class="bdp-sync-page">
    <div class="bdp-sync-header">
        <div class="bdp-sync-title">
            <h1>Sync Queue</h1>
            <p>Products added here are synced by cron one by one. Variation images are copied to store.balencousa.com during sync.</p>
        </div>
        <a class="bdp-sync-btn" href="{{ route('bdp.products') }}">Back to Products</a>
    </div>

    <div class="bdp-sync-stats">
        <a class="bdp-sync-stat {{ ($status ?? 'all') === 'all' ? 'active' : '' }}" href="{{ route('bdp.sync.queue.index', ['status' => 'all']) }}">
            <span>All</span>
            <strong>{{ $counts['all'] ?? 0 }}</strong>
        </a>
        <a class="bdp-sync-stat {{ ($status ?? '') === 'queued' ? 'active' : '' }}" href="{{ route('bdp.sync.queue.index', ['status' => 'queued']) }}">
            <span>Queued</span>
            <strong>{{ $counts['queued'] ?? 0 }}</strong>
        </a>
        <a class="bdp-sync-stat {{ ($status ?? '') === 'processing' ? 'active' : '' }}" href="{{ route('bdp.sync.queue.index', ['status' => 'processing']) }}">
            <span>Processing</span>
            <strong>{{ $counts['processing'] ?? 0 }}</strong>
        </a>
        <a class="bdp-sync-stat {{ ($status ?? '') === 'synced' ? 'active' : '' }}" href="{{ route('bdp.sync.queue.index', ['status' => 'synced']) }}">
            <span>Synced</span>
            <strong>{{ $counts['synced'] ?? 0 }}</strong>
        </a>
        <a class="bdp-sync-stat {{ ($status ?? '') === 'failed' ? 'active' : '' }}" href="{{ route('bdp.sync.queue.index', ['status' => 'failed']) }}">
            <span>Failed</span>
            <strong>{{ $counts['failed'] ?? 0 }}</strong>
        </a>
    </div>

    <div class="bdp-sync-panel">
        <form method="get" action="{{ route('bdp.sync.queue.index') }}" class="bdp-sync-filter">
            <div class="bdp-sync-field">
                <label>Status</label>
                <select name="status">
                    <option value="all" @selected(($status ?? 'all') === 'all')>All</option>
                    <option value="queued" @selected(($status ?? '') === 'queued')>Queued</option>
                    <option value="processing" @selected(($status ?? '') === 'processing')>Processing</option>
                    <option value="synced" @selected(($status ?? '') === 'synced')>Synced</option>
                    <option value="failed" @selected(($status ?? '') === 'failed')>Failed</option>
                </select>
            </div>
            <div class="bdp-sync-field">
                <label>Search</label>
                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Product title, SKU, or Data Pilot ID">
            </div>
            <button class="bdp-sync-btn primary" type="submit">Filter</button>
            <a class="bdp-sync-btn" href="{{ route('bdp.sync.queue.index') }}">Reset</a>
        </form>

        <div class="bdp-sync-table-wrap">
            <table class="bdp-sync-table">
                <thead>
                    <tr>
                        <th class="bdp-col-product">Product</th>
                        <th class="bdp-col-sku">SKU</th>
                        <th class="bdp-col-status">Status</th>
                        <th class="bdp-col-attempts">Attempts</th>
                        <th class="bdp-col-date">Queued</th>
                        <th class="bdp-col-date">Started</th>
                        <th class="bdp-col-date">Synced</th>
                        <th class="bdp-col-note">Last Error / Note</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <div class="bdp-product-name">{{ $item->product_name ?: 'Product #' . $item->product_id }}</div>
                                <div class="bdp-muted">
                                    Data Pilot ID: {{ $item->product_id }}
                                    @if($item->shofy_product_id)
                                        · Shofy ID: {{ $item->shofy_product_id }}
                                    @endif
                                </div>
                            </td>
                            <td>{{ $item->sku ?: '—' }}</td>
                            <td><span class="bdp-status {{ $item->status }}">{{ $item->status }}</span></td>
                            <td>{{ $item->attempts }}</td>
                            <td><span class="bdp-muted">{{ $item->queued_at ?: '—' }}</span></td>
                            <td><span class="bdp-muted">{{ $item->started_at ?: '—' }}</span></td>
                            <td><span class="bdp-muted">{{ $item->synced_at ?: '—' }}</span></td>
                            <td>
                                @if($item->last_error)
                                    <div class="bdp-note error">{{ \Illuminate\Support\Str::limit((string) $item->last_error, 220) }}</div>
                                @else
                                    <div class="bdp-note">{{ \Illuminate\Support\Str::limit((string) $item->shofy_push_note, 180) ?: '—' }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="bdp-sync-empty">No queue items found.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($items->hasPages())
            <div class="bdp-sync-pagination">
                <div class="bdp-sync-pagination-info">
                    Showing {{ $items->firstItem() }} to {{ $items->lastItem() }} of {{ $items->total() }} results
                </div>

                <div class="bdp-sync-pages">
                    @if($items->onFirstPage())
                        <span class="bdp-sync-page-disabled">Previous</span>
                    @else
                        <a class="bdp-sync-page-link" href="{{ $items->previousPageUrl() }}">Previous</a>
                    @endif

                    @php
                        $current = $items->currentPage();
                        $last = $items->lastPage();
                        $start = max(1, $current - 2);
                        $end = min($last, $current + 2);
                    @endphp

                    @if($start > 1)
                        <a class="bdp-sync-page-link" href="{{ $items->url(1) }}">1</a>
                        @if($start > 2)
                            <span class="bdp-sync-page-disabled">...</span>
                        @endif
                    @endif

                    @for($page = $start; $page <= $end; $page++)
                        @if($page === $current)
                            <span class="bdp-sync-page-current">{{ $page }}</span>
                        @else
                            <a class="bdp-sync-page-link" href="{{ $items->url($page) }}">{{ $page }}</a>
                        @endif
                    @endfor

                    @if($end < $last)
                        @if($end < $last - 1)
                            <span class="bdp-sync-page-disabled">...</span>
                        @endif
                        <a class="bdp-sync-page-link" href="{{ $items->url($last) }}">{{ $last }}</a>
                    @endif

                    @if($items->hasMorePages())
                        <a class="bdp-sync-page-link" href="{{ $items->nextPageUrl() }}">Next</a>
                    @else
                        <span class="bdp-sync-page-disabled">Next</span>
                    @endif
                </div>
            </div>
        @else
            <div class="bdp-sync-pagination">
                <div class="bdp-sync-pagination-info">
                    Showing {{ $items->count() }} result{{ $items->count() === 1 ? '' : 's' }}
                </div>
            </div>
        @endif
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
    copy($backup, $view);
    fwrite(STDERR, "Syntax check failed. Restored backup.\n");
    exit(1);
}

echo "Clean Sync Queue UI applied successfully.\n";
echo "Next run:\n";
echo "php artisan optimize:clear\n";
