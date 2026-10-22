@extends('balenco-data-pilot.layout')

@section('title', 'Sync Queue | Data Pilot')

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
.bdp-product-name a{color:inherit;text-decoration:none}
.bdp-product-name a:hover{text-decoration:underline}
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
    padding: 38px 20px;
    text-align: center;
    color: #64748b;
}
.bdp-sync-empty-icon{width:52px;height:52px;border-radius:17px;display:grid;place-items:center;margin:0 auto 14px;background:#eef3ff;color:#1e3a8a}
.bdp-sync-empty-icon svg{width:27px;height:27px}
.bdp-sync-empty h2{font-size:18px;line-height:1.25;color:#0f172a;margin:0 0 7px}
.bdp-sync-empty p{max-width:510px;margin:0 auto 16px;line-height:1.6}
.bdp-sync-empty-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
.bdp-sync-header-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}
.bdp-sync-mobile-hint{display:none;color:#64748b;font-size:12px;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
.bdp-sync-stat:focus-visible,.bdp-sync-btn:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
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
    .bdp-sync-mobile-hint{display:block}
}
@media(max-width:640px){
    .bdp-sync-header{flex-direction:column}
    .bdp-sync-header-actions{width:100%}
    .bdp-sync-header-actions .bdp-sync-btn{flex:1}
    .bdp-sync-page{padding-top:0}
    .bdp-sync-stats{gap:9px}
    .bdp-sync-stat{padding:13px}
}
</style>

<div class="bdp-sync-page">
    <div class="bdp-sync-header">
        <div class="bdp-sync-title">
            <h1>Sync Queue</h1>
            <p>Track products waiting for scheduled sync, review failures, and confirm completed transfers to your connected store.</p>
        </div>
        <div class="bdp-sync-header-actions">
            <a class="bdp-sync-btn" href="{{ route('bdp.products') }}">Choose Products</a>
            <a class="bdp-sync-btn primary" href="{{ route('bdp.cj.import') }}">Import from CJ</a>
        </div>
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
                <label for="queue-status">Status</label>
                <select name="status" id="queue-status">
                    <option value="all" @selected(($status ?? 'all') === 'all')>All</option>
                    <option value="queued" @selected(($status ?? '') === 'queued')>Queued</option>
                    <option value="processing" @selected(($status ?? '') === 'processing')>Processing</option>
                    <option value="synced" @selected(($status ?? '') === 'synced')>Synced</option>
                    <option value="failed" @selected(($status ?? '') === 'failed')>Failed</option>
                </select>
            </div>
            <div class="bdp-sync-field">
                <label for="queue-search">Search</label>
                <input type="search" id="queue-search" name="search" value="{{ $search ?? '' }}" placeholder="Product title, SKU, or Data Pilot ID">
            </div>
            <button class="bdp-sync-btn primary" type="submit">Filter</button>
            <a class="bdp-sync-btn" href="{{ route('bdp.sync.queue.index') }}">Reset</a>
        </form>

        <div class="bdp-sync-mobile-hint">Scroll horizontally to view all queue details →</div>
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
                                <div class="bdp-product-name">@if($item->product_name)<a href="{{ route('bdp.products.edit', $item->product_id) }}">{{ $item->product_name }}</a>@else Product #{{ $item->product_id }} @endif</div>
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
                                <div class="bdp-sync-empty">
                                    <div class="bdp-sync-empty-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M7 9v4h10V9M3 16h18M7 20h10"/></svg>
                                    </div>
                                    @if(($counts['all'] ?? 0) === 0)
                                        <h2>Your sync queue is empty</h2>
                                        <p>First import a product from CJ, review its details, then select it on the Products page to add it to the queue. A configured cron job processes queued items.</p>
                                        <div class="bdp-sync-empty-actions">
                                            <a class="bdp-sync-btn primary" href="{{ route('bdp.cj.import') }}">Import your first product</a>
                                            <a class="bdp-sync-btn" href="{{ route('bdp.products') }}">Open Products</a>
                                        </div>
                                    @else
                                        <h2>No items match your filters</h2>
                                        <p>Try a different status or product name, or clear the filters to see every queued product.</p>
                                        <div class="bdp-sync-empty-actions"><a class="bdp-sync-btn primary" href="{{ route('bdp.sync.queue.index') }}">Clear filters</a></div>
                                    @endif
                                </div>
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