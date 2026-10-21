@extends('balenco-data-pilot.layout')

@section('title', 'Dashboard | Balenco Data Pilot')

@section('content')
@php
    $total = max((int)($stats['all'] ?? 0), 1);
    $syncedPercent = round((($stats['synced'] ?? 0) / $total) * 100);
    $notSyncedPercent = round((($stats['not_synced'] ?? 0) / $total) * 100);
    $reviewPercent = round((($stats['needs_review'] ?? 0) / $total) * 100);
    $failedPercent = round((($stats['failed'] ?? 0) / $total) * 100);
    $draftPercent = round((($stats['draft'] ?? 0) / $total) * 100);
@endphp
<div class="bdp-page-head">
    <div class="bdp-page-title">
        <h1>Dashboard</h1>
        <p>Overview of product sync, pricing, and import activity.</p>
    </div>
    <div class="bdp-status-toast"><span class="bdp-dot"></span> Synced {{ $stats['synced'] ?? 0 }} products and {{ $stats['variations'] ?? 0 }} variations to Balenco.</div>
</div>

<div class="bdp-grid bdp-stats">
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/></svg></div><div><div class="bdp-stat-label">Total Products</div><div class="bdp-stat-number">{{ $stats['all'] ?? 0 }}</div><div class="bdp-stat-note">{{ $stats['variations'] ?? 0 }} variations</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m20 6-11 11-5-5"/></svg></div><div><div class="bdp-stat-label">Synced to Balenco</div><div class="bdp-stat-number">{{ $stats['synced'] ?? 0 }}</div><div class="bdp-stat-note">{{ $syncedPercent }}% of total</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></div><div><div class="bdp-stat-label">Needs Review</div><div class="bdp-stat-number">{{ $stats['needs_review'] ?? 0 }}</div><div class="bdp-stat-note">{{ $reviewPercent }}% of total</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M17 13h4v4"/><path d="M21 13a5 5 0 0 1-5 5"/></svg></div><div><div class="bdp-stat-label">Not Synced</div><div class="bdp-stat-number">{{ $stats['not_synced'] ?? 0 }}</div><div class="bdp-stat-note">{{ $notSyncedPercent }}% of total</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></div><div><div class="bdp-stat-label">Sync Failed</div><div class="bdp-stat-number">{{ $stats['failed'] ?? 0 }}</div><div class="bdp-stat-note">{{ $failedPercent }}% of total</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></div><div><div class="bdp-stat-label">Total Variations</div><div class="bdp-stat-number">{{ $stats['variations'] ?? 0 }}</div><div class="bdp-stat-note">Across products</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.3 3.86-8.6 14.88A2 2 0 0 0 3.4 22h17.2a2 2 0 0 0 1.7-3.26L13.7 3.86a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></div><div><div class="bdp-stat-label">Low Stock Alerts</div><div class="bdp-stat-number">{{ $stats['low_stock'] ?? 0 }}</div><div class="bdp-stat-note">Variations</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-gray"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg></div><div><div class="bdp-stat-label">Draft Products</div><div class="bdp-stat-number">{{ $stats['draft'] ?? 0 }}</div><div class="bdp-stat-note">{{ $draftPercent }}% of total</div></div></div>
</div>

<div class="bdp-actions-row">
    <a class="bdp-action-tile primary" href="{{ route('bdp.cj.import') }}"><span class="bdp-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 17v3h16v-3"/></svg></span><span><strong>Import from CJ</strong><small>Import new products from CJ</small></span></a>
    <a class="bdp-action-tile orange" href="{{ route('bdp.products') }}"><span class="bdp-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15.5-6.2L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15.5 6.2L21 16"/><path d="M16 16h5v5"/></svg></span><span><strong>Sync Selected Products</strong><small>Open Products and sync to Balenco</small></span></a>
    <a class="bdp-action-tile" href="{{ route('bdp.products') }}#bulk-pricing"><span class="bdp-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/></svg></span><span><strong>Bulk Pricing Update</strong><small>Update pricing & margins</small></span></a>
    <a class="bdp-action-tile" href="{{ route('bdp.products') }}"><span class="bdp-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/></svg></span><span><strong>Open Products</strong><small>View all products</small></span></a>
    <a class="bdp-action-tile" href="{{ route('bdp.api.logs') }}" style="justify-content:center"><strong>•••</strong></a>
</div>

<div class="bdp-dashboard-grid">
    <div class="bdp-card">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Sync Overview</h3><span class="bdp-pill muted">Live</span></div>
        <div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap">
            <div class="bdp-donut"><div class="bdp-donut-inner"><strong>{{ $stats['all'] ?? 0 }}</strong><small>Total Products</small></div></div>
            <div class="bdp-legend" style="flex:1">
                <div class="bdp-legend-row"><span><span class="bdp-dot" style="color:#22c55e;display:inline-block;margin-right:8px"></span>Synced</span><strong>{{ $stats['synced'] ?? 0 }} ({{ $syncedPercent }}%)</strong></div>
                <div class="bdp-legend-row"><span><span class="bdp-dot" style="color:#f59e0b;display:inline-block;margin-right:8px"></span>Not Synced</span><strong>{{ $stats['not_synced'] ?? 0 }} ({{ $notSyncedPercent }}%)</strong></div>
                <div class="bdp-legend-row"><span><span class="bdp-dot" style="color:#8b5cf6;display:inline-block;margin-right:8px"></span>Needs Review</span><strong>{{ $stats['needs_review'] ?? 0 }} ({{ $reviewPercent }}%)</strong></div>
                <div class="bdp-legend-row"><span><span class="bdp-dot" style="color:#ef4444;display:inline-block;margin-right:8px"></span>Sync Failed</span><strong>{{ $stats['failed'] ?? 0 }} ({{ $failedPercent }}%)</strong></div>
                <div class="bdp-legend-row"><span><span class="bdp-dot" style="color:#cbd5e1;display:inline-block;margin-right:8px"></span>Draft</span><strong>{{ $stats['draft'] ?? 0 }} ({{ $draftPercent }}%)</strong></div>
            </div>
        </div>
        <div style="margin-top:18px"><a class="bdp-btn ghost" href="{{ route('bdp.products', ['sync' => 'synced']) }}">View synced products →</a></div>
    </div>

    <div class="bdp-card bdp-dashboard-card-short compact-natural">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Recent Import Activity</h3><a class="bdp-btn ghost" href="{{ route('bdp.api.logs') }}">View all</a></div>
        <div class="bdp-activity">
            @forelse($recentLogs as $log)
                <div class="bdp-activity-row">
                    <span class="bdp-activity-dot" style="color:{{ ($log->status_code ?? 0) >= 400 ? '#ef4444' : '#22c55e' }}"></span>
                    <div>
                        <strong>{{ $log->source ?: 'API request' }} #{{ $log->id }}</strong>
                        <small>{{ trim(($log->method ?: 'GET') . ' ' . ($log->path ?: $log->endpoint)) }}</small>
                    </div>
                    <div style="text-align:right">
                        <span class="bdp-pill {{ ($log->status_code ?? 0) >= 400 ? 'danger' : 'success' }}">{{ ($log->status_code ?? 0) >= 400 ? 'Failed' : 'Completed' }}</span><br>
                        <small>{{ optional($log->created_at)->format('M d, H:i') }}</small>
                    </div>
                </div>
            @empty
                <div class="bdp-empty-state">
                    <div><strong>No recent imports yet</strong><span>Start by importing products from CJ or check API logs after your next sync.</span></div>
                    <a class="bdp-btn light" href="{{ route('bdp.cj.import') }}">Import from CJ</a>
                </div>
            @endforelse
        </div>
    </div>

    <div class="bdp-card">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Pricing Rules Summary</h3><a class="bdp-btn light" href="{{ route('bdp.products') }}#bulk-pricing">Edit</a></div>
        <div class="bdp-info-list">
            @foreach($pricingSummary as $label => $value)
                <div class="bdp-info-row"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
            @endforeach
        </div>
        <div style="margin-top:12px"><a class="bdp-btn ghost" href="{{ route('bdp.products') }}#bulk-pricing">View pricing settings →</a></div>
    </div>
</div>

<div class="bdp-two-col-main" style="margin-top:18px">
    <div class="bdp-card is-compact compact-natural">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Category Mapping Summary</h3><a class="bdp-btn ghost" href="{{ route('bdp.categories.mapping') }}">View all</a></div>
        <div class="bdp-small-metrics">
            <div class="bdp-small-metric"><strong>{{ $mappedCategories }}</strong><span>Categories mapped</span></div>
            <div class="bdp-small-metric"><strong>{{ $pendingCategories }}</strong><span>Need mapping</span></div>
        </div>
        <div class="bdp-card-actions"><a class="bdp-btn ghost" href="{{ route('bdp.categories.mapping') }}">Open Category Mapping →</a></div>
    </div>

    <div class="bdp-card bdp-recent-products">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Recent Products</h3><a class="bdp-btn ghost" href="{{ route('bdp.products') }}">View all</a></div>
        <div class="bdp-table-wrap">
            <table class="bdp-table">
                <thead><tr><th>Image</th><th>Product</th><th>SKU</th><th>Sync Status</th><th>Stock</th><th>Action</th></tr></thead>
                <tbody>
                @forelse($recentProducts as $product)
                    <tr>
                        <td>@if($product->featured_image)<img class="bdp-thumb" src="{{ $product->featured_image }}" alt="">@endif</td>
                        <td><div class="bdp-product-title"><a href="{{ route('bdp.products.edit', $product) }}">{{ $product->name }}</a></div><div class="bdp-product-meta">{{ $product->categories ?: 'Uncategorized' }}</div></td>
                        <td>{{ $product->sku ?: '—' }}</td>
                        <td>@if($product->shofy_product_id)<span class="bdp-pill success">Synced</span><br><small>Updated {{ optional($product->balenco_synced_at ?: $product->shofy_pushed_at)->diffForHumans() }}</small>@else<span class="bdp-pill warning">Not Synced</span>@endif</td>
                        <td>{{ $product->variations_total_quantity !== null ? number_format((int) $product->variations_total_quantity) : '—' }}</td>
                        <td><a class="bdp-btn light" href="{{ route('bdp.products.edit', $product) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <div class="bdp-empty-state">
                            <div><strong>No products yet</strong><span>Import your first product from CJ, then it will appear here for review.</span></div>
                            <a class="bdp-btn orange" href="{{ route('bdp.cj.import') }}">Import Product</a>
                        </div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bdp-card is-compact compact-natural">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Tasks / Review Queue</h3><a class="bdp-btn ghost" href="{{ route('bdp.products', ['sync' => 'needs_review']) }}">View all</a></div>
        <div class="bdp-review-list">
            <a class="bdp-review-row" href="{{ route('bdp.products', ['sync' => 'needs_review']) }}"><span>Products needing review</span><span class="bdp-pill danger">{{ $stats['needs_review'] ?? 0 }}</span></a>
            <a class="bdp-review-row" href="{{ route('bdp.products', ['sync' => 'not_synced']) }}"><span>Products not synced</span><span class="bdp-pill danger">{{ $stats['not_synced'] ?? 0 }}</span></a>
            <a class="bdp-review-row" href="{{ route('bdp.products') }}"><span>Low stock alerts</span><span class="bdp-pill warning">{{ $stats['low_stock'] ?? 0 }}</span></a>
            <a class="bdp-review-row" href="{{ route('bdp.products') }}"><span>Total variations</span><span class="bdp-pill info">{{ $stats['variations'] ?? 0 }}</span></a>
            <a class="bdp-review-row" href="{{ route('bdp.products') }}"><span>Draft products</span><span class="bdp-pill muted">{{ $stats['draft'] ?? 0 }}</span></a>
        </div>
        <div style="margin-top:14px"><a class="bdp-btn ghost" href="{{ route('bdp.products', ['sync' => 'needs_review']) }}">Go to review queue →</a></div>
    </div>
</div>

<div class="bdp-footer-note"><span>Recommended Balenco rule: landed cost + $20 margin, rounded to .99, minimum $39.99.</span><span>Last updated: {{ now()->format('H:i') }} ↻</span></div>
@endsection
