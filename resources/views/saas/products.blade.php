@extends('saas.layout')
@section('title', 'My Products')
@section('content')
<div class="page-title"><span class="eyebrow">Catalog</span><h1>My Products</h1><p>Private CJ product drafts belonging only to this workspace. No products are sent to Botble or published.</p></div>
<div class="card">
<div class="split"><h2 style="margin:0">CJ product draft import</h2><span class="tag">Manual only</span></div>
@if(session('cj_product_import'))
    <div class="alert ok mt" role="status">{{ session('cj_product_import') }}</div>
@endif
@error('cj_product')
    <div class="alert mt" role="alert">{{ $message }}</div>
@enderror
@if($canImportCj)
    <p class="micro mt">Search CJ's official catalog and save up to 10 results as private drafts per request. Each CJ list search uses API points. Existing CJ drafts are not duplicated, and non-draft items will not be overwritten.</p>
    <form method="post" action="{{ route('saas.workspace.products.cj.fetch', $workspace->public_id) }}" autocomplete="off">
        @csrf
        <div class="form-field"><label for="cj_keyword">CJ product name or SKU</label>
            <input id="cj_keyword" name="keyword" value="{{ old('keyword') }}" required minlength="2" maxlength="100" placeholder="e.g. linen tablecloth">
            @error('keyword')<span class="error" role="alert">{{ $message }}</span>@enderror
        </div>
        <div class="form-field"><label for="cj_page">CJ results page (1–20)</label>
            <input id="cj_page" name="page" type="number" min="1" max="20" value="{{ old('page', 1) }}" required>
            @error('page')<span class="error" role="alert">{{ $message }}</span>@enderror
        </div>
        <button type="submit" class="btn btn-primary">Fetch and save CJ drafts</button>
    </form>
@else
    <p class="micro mt">Product retrieval is currently unavailable. A verified CJ connection, active workspace, authorized role, import entitlement and separately enabled draft-import feature are required.</p>
@endif
<p class="micro mt">No automatic synchronization or Botble publishing is enabled. Stored supplier prices and inventory may change; verify them before future listing.</p>
</div>
<div class="card mt">
<div class="split"><h2 style="margin:0">Workspace products</h2><span class="tag">{{ $products->total() }} total</span></div>
@if($products->isEmpty())
<div class="empty mt"><h3>No saved drafts yet</h3><p>Use manual CJ draft import when enabled to save products here for review.</p><a href="{{ route('saas.workspace.connections', $workspace->public_id) }}" class="btn">View connection status →</a></div>
@else
<div class="table-wrap mt">
<table class="data-table">
<thead><tr><th>Product</th><th>CJ product ID</th><th>SKU</th><th>Source price (USD)</th><th>Status</th></tr></thead>
<tbody>
@foreach($products as $product)
<tr>
<td>{{ $product->title }}</td>
<td>{{ $product->cj_product_id ?: '—' }}</td>
<td>{{ $product->sku ?: '—' }}</td>
<td>{{ data_get($product->source_snapshot, 'sell_price_usd', '—') }}</td>
<td><span class="tag">{{ ucfirst($product->status) }}</span></td>
</tr>
@endforeach
</tbody>
</table>
</div>
{{ $products->links() }}
@endif
</div>
@endsection
