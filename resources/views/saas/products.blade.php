@extends('saas.layout')
@section('title', 'My Products')
@push('styles')
<style>
.my-product-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:22px}
.my-product-stats .stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px}
.my-product-stats .stat span{font-weight:720;color:var(--sub);font-size:13px}
.my-product-stats .stat b{font-size:30px}
.my-product-toolbar{display:flex;align-items:end;flex-wrap:wrap;gap:12px}
.my-product-toolbar label{display:block;font-size:12px;font-weight:750;margin-bottom:6px}
.my-product-toolbar input,.my-product-toolbar select{border:1px solid #cbd5e1;border-radius:9px;padding:10px;min-height:42px;color:var(--ink);width:100%}
.my-product-toolbar .product-query{flex:2;min-width:215px}
.my-product-toolbar .product-filter{min-width:155px;flex:1}
.my-product-table th{white-space:nowrap}
.my-product-table td{vertical-align:middle}
.my-product-photo{width:62px;height:62px;object-fit:contain;border:1px solid var(--line);border-radius:9px;background:#f8fafc;flex-shrink:0}
.my-product-main{display:flex;align-items:center;gap:12px;min-width:255px}
.my-product-main strong{display:block;color:var(--ink);font-size:14px;line-height:1.35}
.my-product-main small{display:block;max-width:350px;overflow-wrap:anywhere}
.my-product-muted{color:var(--sub);font-size:12px}
@media(max-width:900px){.my-product-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@endpush
@section('content')
<div class="page-title">
    <span class="eyebrow">Catalog management</span>
    <div class="split">
        <div><h1>My Products</h1><p>Products you have imported into this workspace. Review drafts, adjust product information and prepare listings for your connected store.</p></div>
        <a class="btn btn-primary" href="{{ route('saas.workspace.products.cj', $workspace->public_id) }}">+ Import from CJ</a>
    </div>
</div>
<div class="my-product-stats">
    <div class="stat"><span>All products</span><b>{{ number_format($counts['all']) }}</b><small class="micro">In your workspace</small></div>
    <div class="stat"><span>Drafts</span><b>{{ number_format($counts['draft']) }}</b><small class="micro">Available to edit</small></div>
    <div class="stat"><span>Published to Botble</span><b>0</b><small class="micro">Customer publishing not released</small></div>
    <div class="stat"><span>Connected stores</span><b>{{ $connectedStores }}</b><small class="micro">Registered store connections</small></div>
</div>

<div class="card">
    <div class="split"><div><h2 style="margin-bottom:4px">Find & Filter Products</h2><p class="micro" style="margin:0">Search imported titles, CJ product IDs and SKUs. Results always belong to this workspace.</p></div></div>
    <form method="get" action="{{ route('saas.workspace.products', $workspace->public_id) }}" class="my-product-toolbar mt">
        <div class="product-query"><label for="product_q">Product name, CJ ID or SKU</label><input id="product_q" name="q" type="search" maxlength="100" value="{{ $search }}" placeholder="e.g. tablecloth"></div>
        <div class="product-filter"><label for="product_status">Status</label><select id="product_status" name="status">
            <option value="all" @selected($status === 'all')>All products</option>
            <option value="draft" @selected($status === 'draft')>Drafts</option>
            <option value="approved" @selected($status === 'approved')>Reviewed</option>
        </select></div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a class="btn" href="{{ route('saas.workspace.products', $workspace->public_id) }}">Clear</a>
    </form>
</div>
<div class="card mt">
    <div class="split">
        <div><h2 style="margin-bottom:4px">Imported Products</h2><p class="micro" style="margin:0">Edit products here before any future Botble publishing.</p></div>
        <span class="tag">{{ $products->total() }} shown</span>
    </div>
    @if($products->isEmpty())
        <div class="empty mt">
            <h3>No matching imported products</h3>
            <p>Use Import from CJ to find products, preview them and import only your selection.</p>
            <a class="btn btn-primary" href="{{ route('saas.workspace.products.cj', $workspace->public_id) }}">Go to Import from CJ</a>
        </div>
    @else
    <div class="table-wrap mt">
        <table class="data-table my-product-table">
            <thead>
                <tr><th>Product</th><th>SKU / CJ ID</th><th>CJ cost</th><th>Selling</th><th>Status</th><th>Botble sync</th><th>Actions</th></tr>
            </thead>
            <tbody>
            @foreach($products as $product)
            <tr>
                <td><div class="my-product-main">
                    @if(data_get($product->source_snapshot,'image_url'))
                    <img class="my-product-photo" src="{{ data_get($product->source_snapshot,'image_url') }}" alt="" referrerpolicy="no-referrer" loading="lazy">
                    @else<div class="my-product-photo" style="display:grid;place-items:center;font-size:10px;color:#64748b">No image</div>@endif
                    <div><strong>{{ $product->title }}</strong><small class="my-product-muted">{{ $product->category_name ?: data_get($product->source_snapshot,'category','Uncategorized') }}</small></div>
                </div></td>
                <td><strong>{{ $product->sku ?: '—' }}</strong><div class="my-product-muted">{{ $product->cj_product_id ?: '—' }}</div></td>
                <td>{{ data_get($product->source_snapshot,'sell_price_usd') !== null ? '$'.data_get($product->source_snapshot,'sell_price_usd') : '—' }}</td>
                <td>{{ $product->selling_price_usd !== null ? '$'.number_format((float)$product->selling_price_usd,2) : 'Set price' }}</td>
                <td><span class="tag">{{ ucfirst($product->status) }}</span></td>
                <td><span class="tag off">Not published</span></td>
                <td>
                    <a class="btn btn-sm" href="{{ route('saas.workspace.products.edit', ['workspace'=>$workspace->public_id,'product'=>$product->id]) }}">Edit</a>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt">{{ $products->links() }}</div>
    @endif
    <p class="micro mt">Publishing to Botble is not available in the customer portal yet. Connecting a store does not automatically publish drafts; the publishing endpoint requires a separate verified release.</p>
</div>
@endsection
