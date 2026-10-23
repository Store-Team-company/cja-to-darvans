@extends('saas.layout')
@section('title', 'Edit product')
@push('styles')
<style>
.product-edit-layout{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(290px,.85fr);gap:20px}
.product-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.product-edit-grid .wide{grid-column:1/-1}
.product-edit-grid label{display:block;font-size:13px;font-weight:750;margin-bottom:7px}
.product-edit-grid input,.product-edit-grid textarea{display:block;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;color:#12213c}
.product-edit-grid textarea{min-height:160px;resize:vertical}
.product-edit-photo{width:100%;height:255px;object-fit:contain;border-radius:12px;background:#f7f9fc}
.product-edit-meta{display:grid;gap:10px;margin-top:16px}
.product-edit-meta div{border-bottom:1px solid var(--line);padding-bottom:9px;overflow-wrap:anywhere}
.product-edit-meta span{display:block;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:800}
@media(max-width:850px){.product-edit-layout{grid-template-columns:1fr}}
@media(max-width:540px){.product-edit-grid{grid-template-columns:1fr}.product-edit-grid .wide{grid-column:auto}}
</style>
@endpush
@section('content')
<div class="page-title">
    <a class="micro" href="{{ route('saas.workspace.products', $workspace->public_id) }}">← Back to My Products</a>
    <div class="split mt">
        <div><span class="eyebrow">Product catalog</span><h1>Edit product</h1><p>Update the product's customer-facing information. Your original CJ source data remains available for reference.</p></div>
        <span class="tag">Draft · Not published</span>
    </div>
</div>
@if($errors->any())
    <div class="alert" role="alert">Please correct the highlighted fields before saving.</div>
@endif
<div class="product-edit-layout">
    <div>
        <div class="card">
            <div class="split"><h2>Product information</h2><span class="tag">Merchant edits</span></div>
            <form method="post" action="{{ route('saas.workspace.products.update', ['workspace'=>$workspace->public_id,'product'=>$product->id]) }}">
                @csrf
                @method('PATCH')
                <div class="product-edit-grid">
                    <div class="wide"><label for="edit_title">Product title *</label>
                        <input id="edit_title" name="title" value="{{ old('title', $product->title) }}" maxlength="250" minlength="2" required>
                        @error('title')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div><label for="edit_sku">SKU</label>
                        <input id="edit_sku" name="sku" maxlength="180" value="{{ old('sku', $product->sku) }}">
                        @error('sku')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div><label for="edit_category">Category</label>
                        <input id="edit_category" name="category_name" maxlength="200" value="{{ old('category_name', $product->category_name ?? data_get($product->source_snapshot,'category')) }}">
                        @error('category_name')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div><label for="edit_price">Selling price (USD)</label>
                        <input id="edit_price" name="selling_price_usd" type="number" min="0.01" max="999999.99" step="0.01" value="{{ old('selling_price_usd', $product->selling_price_usd) }}" placeholder="e.g. 39.99">
                        @error('selling_price_usd')<span class="error">{{ $message }}</span>@enderror
                    </div>
                    <div><label>CJ source price (read-only)</label>
                        <input value="{{ data_get($product->source_snapshot,'sell_price_usd','Not supplied') }}" disabled aria-label="CJ supplier price, read only">
                    </div>
                    <div class="wide"><label for="edit_description">Product description</label>
                        <textarea id="edit_description" name="description" maxlength="5000" placeholder="Write a clear customer-friendly description">{{ old('description', $product->description) }}</textarea>
                        <p class="micro" style="margin-top:6px">Plain text only. This information is stored in DataPilot and is not pushed to Botble yet.</p>
                        @error('description')<span class="error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="flex mt">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                    <a class="btn" href="{{ route('saas.workspace.products', $workspace->public_id) }}">Save & close later</a>
                </div>
            </form>
        </div>
        <div class="card mt">
            <h2>Botble store publishing</h2>
            <p>Connect and verify the target store first. Product publishing will require the dedicated signed publishing API and a full product-detail and variation review.</p>
            <button class="btn" disabled type="button" title="Botble product publishing is not released">Push to Botble — not available yet</button>
            <p class="micro mt">This button does not send data and cannot change a product's publishing status.</p>
            @forelse($stores as $store)
                <div class="split" style="padding:12px 0;border-top:1px solid var(--line)">
                    <div><strong>{{ $store->name }}</strong><div class="micro">{{ $store->base_url }}</div></div>
                    <span class="tag {{ $store->status === 'connected' ? 'good' : 'off' }}">{{ ucfirst($store->status) }}</span>
                </div>
            @empty
                <p class="micro">No Botble store records found in this workspace.</p>
            @endforelse
        </div>
    </div>
    <aside>
        <div class="card">
            <h2>CJ supplier reference</h2>
            @if(data_get($product->source_snapshot,'image_url'))
                <img class="product-edit-photo" src="{{ data_get($product->source_snapshot,'image_url') }}" referrerpolicy="no-referrer" alt="CJ supplier product image">
            @else<div class="empty">No supplier image currently stored</div>@endif
            <div class="product-edit-meta">
                <div><span>CJ Product ID</span><strong>{{ $product->cj_product_id ?: '—' }}</strong></div>
                <div><span>Source SKU</span><strong>{{ data_get($product->source_snapshot,'source_sku', $product->sku ?: '—') }}</strong></div>
                <div><span>Source category</span><strong>{{ data_get($product->source_snapshot,'category','—') }}</strong></div>
                <div><span>Source price</span><strong>{{ data_get($product->source_snapshot,'sell_price_usd') !== null ? '$'.data_get($product->source_snapshot,'sell_price_usd') : '—' }}</strong></div>
                <div><span>Last CJ snapshot</span><strong>{{ data_get($product->source_snapshot,'fetched_at','—') }}</strong></div>
                <div><span>Merchant edited</span><strong>{{ $product->merchant_edited_at?->format('Y-m-d H:i') ?? 'Not yet' }}</strong></div>
            </div>
        </div>
    </aside>
</div>
@endsection
