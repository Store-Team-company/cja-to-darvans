@extends('balenco-data-pilot.layout')

@section('title', 'Products | Balenco Data Pilot')

@section('content')
<div class="bdp-page-head">
    <div class="bdp-page-title">
        <h1>Products</h1>
        <p>Manage imported CJ products, pricing, categories, variation images, and Balenco sync status.</p>
    </div>
</div>

@php($tabs = [
    'all' => 'All',
    'not_synced' => 'Not Synced',
    'synced' => 'Synced to Balenco',
    'needs_review' => 'Needs Review',
    'failed' => 'Sync Failed',
])

<div class="bdp-grid bdp-stats" style="grid-template-columns:repeat(5,minmax(150px,1fr))">
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/></svg></div><div><div class="bdp-stat-label">All Products</div><div class="bdp-stat-number">{{ $counts['all'] ?? 0 }}</div><div class="bdp-stat-note">Imported into Data Pilot</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m20 6-11 11-5-5"/></svg></div><div><div class="bdp-stat-label">Synced</div><div class="bdp-stat-number">{{ $counts['synced'] ?? 0 }}</div><div class="bdp-stat-note">Live in Balenco</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15.5-6.2L3 8"/><path d="M3 3v5h5"/></svg></div><div><div class="bdp-stat-label">Not Synced</div><div class="bdp-stat-number">{{ $counts['not_synced'] ?? 0 }}</div><div class="bdp-stat-note">Waiting for push</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg></div><div><div class="bdp-stat-label">Needs Review</div><div class="bdp-stat-number">{{ $counts['needs_review'] ?? 0 }}</div><div class="bdp-stat-note">Missing data</div></div></div>
    <div class="bdp-stat-card"><div class="bdp-stat-icon is-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg></div><div><div class="bdp-stat-label">Failed</div><div class="bdp-stat-number">{{ $counts['failed'] ?? 0 }}</div><div class="bdp-stat-note">Need fixing</div></div></div>
</div>

<div class="bdp-card" id="product-controls">
    <div class="bdp-card-header">
        <div>
            <h3 class="bdp-card-title">Product Controls</h3>
            <p class="bdp-card-subtitle">Select products, then sync, export, bulk edit, price, or assign categories.</p>
        </div>
        <div class="bdp-filter-tabs">
            @foreach($tabs as $key => $label)
                <a href="{{ route('bdp.products', ['sync' => $key]) }}" class="bdp-btn {{ ($sync ?? 'all') === $key ? 'orange' : 'light' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
            @endforeach
        </div>
    </div>

    <div class="bdp-alert" style="background:#fff7ed;border-color:#fed7aa;color:#9a3412">
        <strong>Image rule:</strong> parent product gallery is still manual. Variation images are copied into Balenco/Shofy storage when possible and reused by color across sizes.
    </div>

    <div class="bdp-grid bdp-grid-2">
        <div>
            <label>Live sync status</label>
            <select form="bdp-products-form" name="sync_status">
                <option value="draft" selected>Sync as Draft — safest for live site</option>
                <option value="published">Sync as Published</option>
            </select>
            <div class="bdp-input-help">Recommended: sync as draft, review in Balenco, then publish.</div>
        </div>
        <div>
            <label>Variation image mode</label>
            <label style="display:flex;align-items:center;gap:9px;margin-top:10px;font-weight:650">
                <input form="bdp-products-form" class="bdp-checkbox" type="checkbox" name="copy_variation_images" value="1" checked style="width:auto">
                Copy CJ variation images into Balenco storage before sync
            </label>
            <div class="bdp-input-help">Copied once per color and reused across sizes.</div>
        </div>
    </div>

    <div class="bdp-toolbar" style="margin-top:18px">
        <button class="bdp-btn orange" type="submit" form="bdp-products-form" formaction="{{ route('bdp.push') }}" formmethod="post">Sync Selected to Balenco</button>
        <button class="bdp-btn light" type="submit" form="bdp-products-form" formaction="{{ route('bdp.export') }}" formmethod="post">Export Selected CSV</button>
        <button class="bdp-btn light" type="submit" form="bdp-products-form" formaction="{{ route('bdp.bulk.edit') }}" formmethod="post">Bulk SEO / Content Edit</button>
        <a class="bdp-btn light" href="{{ route('bdp.categories.mapping') }}">Category Mapping</a>
    </div>
</div>

<div class="bdp-grid bdp-grid-2">
    <div class="bdp-card">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Quick Category Update</h3></div>
        <p class="bdp-card-subtitle">Apply a Data Pilot category to selected products. Use Category Mapping to connect it to a real Balenco category.</p>
        <div class="bdp-form-grid" style="margin-top:14px">
            <div><label>Category name</label><input form="bdp-products-form" type="text" name="category" placeholder="e.g. Heels & Pumps"></div>
            <div><label>Mode</label><select form="bdp-products-form" name="mode"><option value="replace">Replace categories</option><option value="append">Append category</option></select></div>
        </div>
        <div style="margin-top:14px"><button class="bdp-btn light" type="submit" form="bdp-products-form" formaction="{{ route('bdp.categories.bulk.assign') }}" formmethod="post">Apply to Selected</button></div>
    </div>

    <div class="bdp-card" id="bulk-pricing">
        <div class="bdp-card-header"><h3 class="bdp-card-title">Bulk Pricing Calculator</h3></div>
        <p class="bdp-card-subtitle">Calculate Balenco selling price using: CJ cost + CJ shipping + your margin.</p>
        <div class="bdp-form-grid" style="margin-top:14px">
            <div><label>Destination country</label><select form="bdp-products-form" name="country_code"><option value="US" selected>United States (US)</option><option value="GB">United Kingdom (GB)</option><option value="CA">Canada (CA)</option><option value="AU">Australia (AU)</option><option value="DE">Germany (DE)</option><option value="FR">France (FR)</option></select></div>
            <div><label>Quantity for shipping</label><input form="bdp-products-form" type="number" name="quantity" value="1" min="1" max="100"></div>
            <div><label>Margin type</label><select form="bdp-products-form" name="margin_type"><option value="fixed" selected>Fixed amount</option><option value="percent">Percentage markup</option></select></div>
            <div><label>Margin value</label><input form="bdp-products-form" type="number" name="margin_value" value="20" step="0.01" min="0"></div>
            <div><label>Minimum selling price</label><input form="bdp-products-form" type="number" name="minimum_price" value="39.99" step="0.01" min="0"></div>
            <div><label>Retail rounding</label><select form="bdp-products-form" name="round_mode"><option value="99" selected>Round up to .99</option><option value="95">Round up to .95</option><option value="00">Round up to whole dollar</option><option value="none">No rounding</option></select></div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-weight:650"><input form="bdp-products-form" class="bdp-checkbox" type="checkbox" name="refresh_shipping" value="1" checked style="width:auto"> Refresh CJ inventory + product shipping first if needed.</label>
        <div style="margin-top:14px"><button class="bdp-btn orange" type="submit" form="bdp-products-form" formaction="{{ route('bdp.bulk.pricing') }}" formmethod="post">Calculate Price</button></div>
    </div>
</div>

<form id="bdp-products-form" method="post">
    @csrf
    <div class="bdp-card">
        <div class="bdp-card-header">
            <h3 class="bdp-card-title">Product List</h3>
            <div class="bdp-card-subtitle">Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} of {{ $products->total() }} products</div>
        </div>
        <div class="bdp-table-wrap">
            <table class="bdp-table">
                <thead>
                    <tr><th><input class="bdp-checkbox" type="checkbox" onclick="document.querySelectorAll('[name=\'products[]\']').forEach(cb => cb.checked = this.checked)"></th><th>Product</th><th>SKU</th><th>Variations</th><th>CJ Cost</th><th>Shipping</th><th>Landed</th><th>Selling</th><th>Stock</th><th>Status</th><th>Balenco Sync</th><th>Actions</th></tr>
                </thead>
                <tbody>
                @forelse($products as $product)
                    @php($displayCost = $product->min_variation_cost ?: $product->cost_per_item ?: $product->min_variation_price ?: $product->price)
                    <tr>
                        <td><input class="bdp-checkbox" type="checkbox" name="products[]" value="{{ $product->id }}"></td>
                        <td>
                            <div class="bdp-product-cell">
                                @if($product->featured_image)<img class="bdp-thumb" src="{{ $product->featured_image }}" alt="">@else<div class="bdp-thumb"></div>@endif
                                <div><div class="bdp-product-title">{{ $product->name }}</div><div class="bdp-product-meta">{{ $product->categories ?: 'No category' }}</div></div>
                            </div>
                        </td>
                        <td>{{ $product->sku ?: '—' }}</td>
                        <td>{{ $product->variations_count }}</td>
                        <td>{{ $displayCost !== null && $displayCost !== '' ? '$' . number_format((float) $displayCost, 2) : '—' }}</td>
                        <td>@if($product->cj_shipping_fee !== null)<strong>${{ number_format((float)$product->cj_shipping_fee, 2) }}</strong>@if($product->cj_shipping_country)<br><small>{{ $product->cj_shipping_country }}</small>@endif @if($product->cj_shipping_method)<br><small>{{ \Illuminate\Support\Str::limit($product->cj_shipping_method, 24) }}</small>@endif @else <span class="bdp-pill muted">Refresh CJ</span> @endif</td>
                        <td>{{ $product->landed_cost !== null ? '$' . number_format((float) $product->landed_cost, 2) : '—' }}</td>
                        <td>{{ $product->price !== null && $product->price !== '' ? '$' . number_format((float) $product->price, 2) : '—' }}</td>
                        <td>{{ $product->variations_total_quantity !== null ? number_format((int)$product->variations_total_quantity) : '—' }}</td>
                        <td><span class="bdp-pill {{ $product->status === 'published' ? 'success' : 'muted' }}">{{ $product->status }}</span></td>
                        <td>
                            @if($product->balenco_sync_status === 'sync_failed')
                                <span class="bdp-pill danger">Sync Failed</span><br><small>{{ \Illuminate\Support\Str::limit($product->balenco_sync_message ?: $product->shofy_push_note, 80) }}</small>
                            @elseif(!empty($product->shofy_product_id))
                                <span class="bdp-pill success">{{ $product->balenco_sync_status === 'updated_in_balenco' ? 'Updated' : 'Synced' }}</span><br>
                                <small>Balenco ID: {{ $product->shofy_product_id }}</small><br>
                                <small>{{ optional($product->balenco_synced_at ?: $product->shofy_pushed_at)->format('Y-m-d H:i') }}</small><br>
                                @if(!is_null($product->shofy_variations_count))<small>{{ $product->shofy_variations_count }} variations synced</small>@endif
                            @else
                                <span class="bdp-pill muted">Not Synced</span>
                            @endif
                        </td>
                        <td><a class="bdp-btn light" href="{{ route('bdp.products.edit', $product) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="12">No products yet. Import products first.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px">{{ $products->links() }}</div>
    </div>
</form>
@endsection
