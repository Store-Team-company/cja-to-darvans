@extends('saas.layout')
@section('title', 'My Products')
@section('content')
<div class="page-title"><span class="eyebrow">Catalog</span><h1>My Products</h1><p>Private product records in your own workspace. Review and publishing workflows will be enabled after connector QA.</p></div>
<div class="card">
<div class="split"><h2 style="margin:0">Workspace products</h2><span class="tag">{{ $products->total() }} total</span></div>
@if($products->isEmpty())
<div class="empty mt"><h3>Start your product catalog</h3><p>When your CJ and Botble integrations are paired and your subscription is active, imported products will appear here. Imports are disabled in this development release.</p><a href="{{ route('saas.workspace.connections', $workspace->public_id) }}" class="btn">View connection status →</a></div>
@else<div class="table-wrap mt"><table class="data-table"><thead><tr><th>Product</th><th>CJ reference</th><th>SKU</th><th>Status</th></tr></thead><tbody>
@foreach($products as $product)<tr><td>{{ $product->title }}</td><td>{{ $product->cj_product_id ?: '—' }}</td><td>{{ $product->sku ?: '—' }}</td><td><span class="tag">{{ ucfirst($product->status) }}</span></td></tr>@endforeach
</tbody></table></div>{{ $products->links() }}@endif
</div>
@endsection
