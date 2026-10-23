@extends('saas.layout')
@section('title', 'Import from CJ')
@push('styles')
<style>
.cj-search-grid{display:grid;grid-template-columns:2fr 1.2fr 1fr 1fr 90px 120px;gap:12px;align-items:end}
.cj-search-grid label,.cj-direct-grid label{display:block;font-size:12px;font-weight:750;margin-bottom:5px}
.cj-search-grid input,.cj-search-grid select,.cj-direct-grid input,.cj-direct-grid select{width:100%;min-width:0;height:42px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;padding:8px 10px;color:#12213c}
.cj-search-actions{display:flex;gap:9px;margin-top:14px;align-items:center;flex-wrap:wrap}
.cj-direct-grid{display:grid;grid-template-columns:180px 1fr auto;gap:12px;align-items:end}
.cj-results-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}
.cj-result-card{border:1px solid var(--line);border-radius:13px;overflow:hidden;display:flex;flex-direction:column;background:#fff;min-width:0}
.cj-result-media{height:190px;background:#f5f8fd;position:relative;display:flex;align-items:center;justify-content:center}
.cj-result-media img{width:100%;height:100%;object-fit:contain}
.cj-result-media .cj-select{position:absolute;top:9px;left:9px;background:#fff;border-radius:8px;padding:6px 8px;font-size:12px;font-weight:750;box-shadow:0 3px 12px #11182718}
.cj-result-media .cj-preview{position:absolute;top:9px;right:9px}
.cj-result-details{padding:13px;display:flex;flex-direction:column;gap:6px;flex:1;min-width:0}
.cj-result-details strong{font-size:13px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.cj-result-details small{overflow-wrap:anywhere}
.cj-result-footer{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:9px;gap:8px}
.cj-result-row{display:flex;gap:14px;align-items:center}
.cj-result-row img{width:66px;height:66px;object-fit:contain;border-radius:9px;background:#f8fafc}
.cj-result-row strong{font-size:13px;line-height:1.4}
.cj-summary{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.cj-preview-dialog{max-width:530px;width:calc(100% - 32px);border:1px solid var(--line);border-radius:16px;padding:24px;box-shadow:0 30px 90px #101b3650}
.cj-preview-dialog::backdrop{background:#0f172a88}
.cj-preview-dialog img{width:100%;max-height:280px;object-fit:contain;background:#f8fafc;border-radius:12px}
.cj-results-actions{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-top:15px}
.cj-results-actions label{display:inline-flex;align-items:center;gap:8px;font-weight:650}
@media(max-width:1100px){.cj-search-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.cj-results-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:700px){.cj-search-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cj-direct-grid{grid-template-columns:1fr}.cj-results-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:470px){.cj-search-grid,.cj-results-grid{grid-template-columns:1fr}.cj-result-media{height:230px}}
</style>
@endpush
@section('content')
<div class="page-title">
    <span class="eyebrow">Catalog</span>
    <h1>Import from CJ</h1>
    <p>Search CJ, preview products, select the ones you need, and import them into My Products. Nothing is sent to Botble.</p>
</div>

@if(session('cj_product_import'))
<div class="alert ok" role="status">{{ session('cj_product_import') }}</div>
@endif
@error('cj_product')
<div class="alert" role="alert">{{ $message }}</div>
@enderror

<div class="card">
    <div class="split"><h2 style="margin:0">Search CJ Products</h2><span class="tag">Search first · Import selected</span></div>
    @if($canImportCj)
    <p class="micro mt">Use the same search controls as the admin panel. Searches cost CJ API points and are limited to once per minute per workspace. Search results are private and expire after 15 minutes; searching does not import anything.</p>
    @php($f = $cjPreview['filters'] ?? [])
    <form method="post" action="{{ route('saas.workspace.products.cj.search', $workspace->public_id) }}" autocomplete="off">
        @csrf
        <input type="hidden" name="mode" value="search">
        <div class="cj-search-grid">
            <div><label for="cj_keyword">Search keyword or product SKU</label>
                <input id="cj_keyword" name="keyword" minlength="2" maxlength="100" required placeholder="e.g. table cloths" value="{{ old('keyword', $f['keyword'] ?? '') }}">
            </div>
            <div><label for="cj_country">Warehouse / inventory country</label>
                <select name="country_code" id="cj_country">
                    <option value="">All warehouses</option>
                    @foreach(['US'=>'United States','CN'=>'China','GB'=>'United Kingdom','DE'=>'Germany'] as $code=>$name)
                        <option value="{{ $code }}" @selected(old('country_code', $f['country_code'] ?? '') === $code)>{{ $code }} — {{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="cj_min">Min CJ price (USD)</label>
                <input id="cj_min" name="min_price" type="number" min="0" max="9999999" step="0.01" value="{{ old('min_price', $f['min_price'] ?? '') }}" placeholder="0">
            </div>
            <div><label for="cj_max">Max CJ price (USD)</label>
                <input id="cj_max" name="max_price" type="number" min="0" max="9999999" step="0.01" value="{{ old('max_price', $f['max_price'] ?? '') }}" placeholder="80">
            </div>
            <div><label for="cj_size">Results</label>
                <select name="size" id="cj_size">
                    @foreach([10,20,50] as $size)
                    <option value="{{ $size }}" @selected((int) old('size', $f['size'] ?? 20) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="cj_page">CJ page (1–20)</label>
                <input id="cj_page" name="page" type="number" min="1" max="20" required value="{{ old('page', $f['page'] ?? 1) }}">
            </div>
        </div>
        <div class="cj-search-actions">
            <label for="cj_order" style="font-size:13px">Sort by</label>
            <select name="order_by" id="cj_order" style="padding:10px;border-radius:9px;border:1px solid #cbd5e1">
                @foreach([0=>'Best match',1=>'Listing count',2=>'Price',3=>'Newest',4=>'Inventory'] as $value=>$name)
                <option value="{{ $value }}" @selected((int) old('order_by', $f['order_by'] ?? 0) === $value)>{{ $name }}</option>
                @endforeach
            </select>
            <select name="sort" aria-label="Sort direction" style="padding:10px;border-radius:9px;border:1px solid #cbd5e1">
                <option value="desc" @selected(old('sort', $f['sort'] ?? 'desc') === 'desc')>Descending</option>
                <option value="asc" @selected(old('sort', $f['sort'] ?? 'desc') === 'asc')>Ascending</option>
            </select>
            <input type="hidden" name="view" value="{{ $cjView }}">
            <button class="btn btn-primary" type="submit">Search CJ catalog</button>
            <a class="btn" href="{{ route('saas.workspace.products', $workspace->public_id) }}">Reset</a>
        </div>
    </form>
    <details class="mt">
        <summary style="cursor:pointer;font-weight:750">Find product directly by CJ Product ID / SKU</summary>
        <p class="micro" style="margin-top:10px">Lookup still opens a preview. The product is only imported after you select it.</p>
        <form method="post" action="{{ route('saas.workspace.products.cj.search', $workspace->public_id) }}" class="cj-direct-grid">
            @csrf
            <input type="hidden" name="mode" value="direct">
            <div><label for="cj_id_type">Identifier type</label>
                <select name="id_type" id="cj_id_type">
                    <option value="pid">CJ Product ID / PID</option>
                    <option value="productSku">Product SKU / SPU</option>
                    <option value="variantSku">Variant SKU</option>
                </select>
            </div>
            <div><label for="cj_identifier">Product identifier</label>
                <input name="identifier" id="cj_identifier" minlength="3" maxlength="160" required placeholder="Paste CJ PID, SPU or variant SKU">
            </div>
            <div><input type="hidden" name="view" value="{{ $cjView }}"><button class="btn" type="submit">Find and preview</button></div>
        </form>
    </details>
    @else
    <p class="micro mt">Catalog access requires an active workspace, a current CJ connection, an authorized importer role and enabled CJ draft-import access.</p>
    <a class="btn mt" href="{{ route('saas.workspace.connections', $workspace->public_id) }}">Check CJ connection</a>
    @endif
</div>

<div class="card mt">
    <div class="split"><h2 style="margin:0">CJ search results</h2>
        @if($cjPreview)<span class="tag good">{{ count($cjPreview['products']) }} shown · {{ number_format($cjPreview['total']) }} total CJ matches</span>@endif
    </div>
    @if(!$cjPreview)
        <div class="empty mt"><h3>Search CJ to preview products</h3>
            <p>View images, title, CJ price, SKU and category, then choose the products to import. No results are saved automatically.</p>
            @if($cjPreviewKey !== '')<p class="micro">The previous search expired or is no longer available. Run a new search.</p>@endif
        </div>
    @else
        @php($items = $cjPreview['products'])
        <div class="cj-results-actions">
            <div class="cj-summary">
                <span class="tag">CJ Page {{ $cjPreview['filters']['page'] ?? 1 }}</span>
                <span class="tag">Preview valid for 15 minutes</span>
            </div>
            <div class="flex">
                <a class="btn btn-sm {{ $cjView === 'grid' ? 'btn-primary' : '' }}" href="{{ route('saas.workspace.products', ['workspace'=>$workspace->public_id,'cj_preview'=>$cjPreviewKey,'view'=>'grid']) }}">Grid View</a>
                <a class="btn btn-sm {{ $cjView === 'list' ? 'btn-primary' : '' }}" href="{{ route('saas.workspace.products', ['workspace'=>$workspace->public_id,'cj_preview'=>$cjPreviewKey,'view'=>'list']) }}">List View</a>
            </div>
        </div>
        @if(empty($items))
        <div class="empty mt"><h3>No products found</h3><p>Try broader keywords or remove country and price limits.</p></div>
        @else
        <form method="post" action="{{ route('saas.workspace.products.cj.import', $workspace->public_id) }}" id="cj-selected-form">
            @csrf
            <input type="hidden" name="cj_preview" value="{{ $cjPreviewKey }}">
            <input type="hidden" name="view" value="{{ $cjView }}">
            <div class="cj-results-actions">
                <label><input id="cj-select-all" type="checkbox" style="width:17px;height:17px"> Select all visible</label>
                <span class="micro"><strong id="cj-selected-count">0</strong> selected</span>
                <button class="btn btn-primary" type="submit" id="cj-import-selected" disabled>Import selected as drafts</button>
            </div>
            @if($cjView === 'grid')
                <div class="cj-results-grid">
                @foreach($items as $item)
                <div class="cj-result-card">
                    <div class="cj-result-media">
                        @if($item['image'])<img src="{{ $item['image'] }}" alt="{{ $item['title'] }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="micro">No image</span>@endif
                        <label class="cj-select"><input type="checkbox" class="cj-check" name="items[]" value="{{ $item['id'] }}" aria-label="Select {{ $item['title'] }}"> Select</label>
                        <button class="btn btn-sm cj-preview cj-preview-open" type="button"
                            data-name="{{ $item['title'] }}" data-sku="{{ $item['sku'] }}" data-pid="{{ $item['id'] }}"
                            data-price="{{ $item['price'] }}" data-category="{{ $item['category'] }}" data-image="{{ $item['image'] }}">Preview</button>
                    </div>
                    <div class="cj-result-details">
                        <strong title="{{ $item['title'] }}">{{ $item['title'] }}</strong>
                        <small class="micro">SKU: {{ $item['sku'] ?: '—' }}</small>
                        <small class="micro">PID: {{ $item['id'] }}</small>
                        <small class="micro">Category: {{ $item['category'] ?: '—' }}</small>
                        <div class="cj-result-footer"><strong>{{ $item['price'] !== null ? '$'.$item['price'] : 'Price unavailable' }}</strong>
                            @if(in_array($item['id'], $cjSavedIds, true))<span class="tag">Saved</span>@endif
                        </div>
                    </div>
                </div>
                @endforeach
                </div>
            @else
                <div class="table-wrap mt">
                <table class="data-table">
                    <thead><tr><th>Select</th><th>Product</th><th>SKU / PID</th><th>CJ cost</th><th>Category</th><th>Action</th></tr></thead>
                    <tbody>
                    @foreach($items as $item)
                    <tr>
                        <td><input class="cj-check" type="checkbox" name="items[]" value="{{ $item['id'] }}" aria-label="Select {{ $item['title'] }}"></td>
                        <td><div class="cj-result-row">
                            @if($item['image'])<img src="{{ $item['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif
                            <strong>{{ $item['title'] }}</strong></div></td>
                        <td><small>{{ $item['sku'] ?: '—' }}</small><div class="micro">{{ $item['id'] }}</div></td>
                        <td>{{ $item['price'] !== null ? '$'.$item['price'] : '—' }}</td>
                        <td>{{ $item['category'] ?: '—' }}</td>
                        <td><button class="btn btn-sm cj-preview-open" type="button"
                            data-name="{{ $item['title'] }}" data-sku="{{ $item['sku'] }}" data-pid="{{ $item['id'] }}"
                            data-price="{{ $item['price'] }}" data-category="{{ $item['category'] }}" data-image="{{ $item['image'] }}">Preview</button>
                            @if(in_array($item['id'], $cjSavedIds, true))<span class="tag">Saved</span>@endif
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </form>
        @endif
    @endif
    <p class="micro mt">Search results are not products in your workspace until you click <strong>Import selected as drafts</strong>. Existing approved products are never overwritten. No automated sync or Botble publishing occurs.</p>
</div>

<div class="card mt">
    <div class="split">
        <div><h2 style="margin:0">Your imported products</h2><p class="micro" style="margin:9px 0 0">Selected products are saved as private drafts under My Products. You can review and edit them there.</p></div>
        <a class="btn btn-primary" href="{{ route('saas.workspace.products', $workspace->public_id) }}">Open My Products →</a>
    </div>
</div>

<dialog class="cj-preview-dialog" id="cj-preview-dialog" aria-label="CJ product preview">
    <div class="split"><h2>Product preview</h2><button class="btn btn-sm" id="cj-close-preview" type="button">Close</button></div>
    <img id="cj-preview-image" hidden alt="CJ product image" referrerpolicy="no-referrer">
    <h3 id="cj-preview-title" style="margin-top:16px"></h3>
    <p id="cj-preview-meta" class="micro" style="white-space:pre-line;overflow-wrap:anywhere"></p>
    <p class="micro">Preview only; select this product in the results to import it as a draft.</p>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const checks = [...document.querySelectorAll('.cj-check')];
    const all = document.getElementById('cj-select-all');
    const count = document.getElementById('cj-selected-count');
    const submit = document.getElementById('cj-import-selected');
    function update() {
        const n = checks.filter(c => c.checked).length;
        if (count) count.textContent = String(n);
        if (submit) submit.disabled = !n;
        if (all) { all.checked = n === checks.length && n > 0; all.indeterminate = n > 0 && n < checks.length; }
    }
    if (all) all.addEventListener('change', () => { checks.forEach(c => c.checked = all.checked); update(); });
    checks.forEach(c => c.addEventListener('change', update));
    update();

    const dialog = document.getElementById('cj-preview-dialog');
    document.querySelectorAll('.cj-preview-open').forEach(button => button.addEventListener('click', () => {
        const image = document.getElementById('cj-preview-image');
        image.hidden = !button.dataset.image;
        if (button.dataset.image) image.src = button.dataset.image; else image.removeAttribute('src');
        document.getElementById('cj-preview-title').textContent = button.dataset.name || 'CJ Product';
        document.getElementById('cj-preview-meta').textContent =
            'SKU: ' + (button.dataset.sku || '—') + '\nPID: ' + (button.dataset.pid || '—') +
            '\nCJ cost: ' + (button.dataset.price ? '$' + button.dataset.price : 'Unavailable') +
            '\nCategory: ' + (button.dataset.category || '—');
        dialog.showModal();
    }));
    document.getElementById('cj-close-preview')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
});
</script>
@endsection
