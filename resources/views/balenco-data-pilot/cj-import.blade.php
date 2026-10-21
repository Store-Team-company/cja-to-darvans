@extends('balenco-data-pilot.layout')

@section('title', 'CJ API Import | Balenco Data Pilot')

@section('content')
<style>
    .cj-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}
    .cj-hero h1{font-size:30px;line-height:1.1;margin:0 0 7px;color:#0f172a}
    .cj-hero p{margin:0;color:#64748b;max-width:920px}
    .cj-hero-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .cj-badge{display:inline-flex;align-items:center;gap:8px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:9px 13px;font-weight:750;font-size:12px;white-space:nowrap}
    .cj-layout{display:grid;grid-template-columns:1fr;gap:14px;align-items:start}
    .cj-panel{background:#fff;border:1px solid var(--bdp-border);border-radius:var(--bdp-radius);box-shadow:var(--bdp-shadow);padding:18px;margin-bottom:14px}
    .cj-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
    .cj-panel h2,.cj-panel h3{margin:0;color:#0f172a}.cj-panel h2{font-size:18px}.cj-panel h3{font-size:17px}.cj-panel .muted{color:#64748b;font-size:13px;margin:4px 0 0}
    .cj-search-compact{display:grid;grid-template-columns:2.4fr 1.4fr .7fr .7fr .75fr .9fr auto auto;gap:10px;align-items:end}
    .cj-direct-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end}
    .cj-form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:13px}.cj-span-12{grid-column:span 12}.cj-span-6{grid-column:span 6}.cj-span-4{grid-column:span 4}.cj-span-3{grid-column:span 3}.cj-span-2{grid-column:span 2}
    .cj-inline-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:4px}.cj-help-row{display:flex;align-items:center;justify-content:space-between;gap:14px;border-top:1px solid #edf1f7;margin-top:16px;padding-top:14px;color:#64748b;font-size:13px}
    .cj-results-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:15px}.cj-results-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.cj-view-toggle{display:flex;gap:8px;flex-wrap:wrap}
    .cj-product-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.cj-product-card{border:1px solid var(--bdp-border);border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 6px 18px rgba(15,23,42,.035);display:flex;flex-direction:column;min-height:100%}.cj-product-image{position:relative;aspect-ratio:1/1;background:#f8fafc;display:grid;place-items:center;overflow:hidden}.cj-product-image img{width:100%;height:100%;object-fit:cover}.cj-product-image .cj-no-img{color:#94a3b8;font-size:12px}.cj-product-body{padding:14px;display:flex;flex-direction:column;gap:10px;flex:1}.cj-product-title{font-weight:850;color:#0f172a;line-height:1.28;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px}.cj-product-meta{display:grid;gap:5px;color:#64748b;font-size:12px;line-height:1.35}.cj-product-meta b{color:#334155}.cj-product-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto}.cj-price{font-size:18px;font-weight:900;color:#0f172a}
    .cj-empty{border:1px dashed #cbd5e1;border-radius:16px;background:#fbfcff;padding:28px;text-align:center}.cj-empty h3{margin:0 0 8px}.cj-empty p{margin:0 auto 16px;color:#64748b;max-width:560px}.cj-table-product{display:flex;align-items:center;gap:12px;min-width:320px}.cj-table-product img{width:56px;height:56px;object-fit:cover;border-radius:12px;border:1px solid var(--bdp-border)}.cj-table-title{font-weight:850;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.cj-table-sub{font-size:12px;color:#64748b;margin-top:3px}.cj-mobile-only{display:none}
    .cj-results-panel{margin-top:14px}.cj-panel.search-panel{padding:18px}.cj-panel.import-panel{padding:0;overflow:hidden}.cj-accordion-head{width:100%;border:0;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:14px;text-align:left;padding:16px 18px;cursor:pointer;color:#0f172a}.cj-accordion-head:hover{background:#fbfcff}.cj-accordion-title h2{font-size:18px;margin:0}.cj-accordion-title p{font-size:13px;color:#64748b;margin:4px 0 0}.cj-accordion-chevron{transition:transform .18s ease}.cj-accordion-body{display:none;border-top:1px solid #edf1f7;padding:16px 18px}.cj-accordion.is-open .cj-accordion-body{display:block}.cj-accordion.is-open .cj-accordion-chevron{transform:rotate(180deg)}
    .cj-helper-popover{display:none;position:fixed;right:24px;top:76px;width:min(430px,calc(100vw - 32px));max-height:calc(100vh - 110px);overflow:auto;background:#fff;border:1px solid var(--bdp-border);border-radius:20px;box-shadow:0 24px 70px rgba(15,23,42,.20);z-index:100;padding:18px}.cj-helper-popover.is-open{display:block}.cj-helper-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.14);z-index:90}.cj-helper-backdrop.is-open{display:block}.cj-helper-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.cj-helper-head h3{font-size:18px;margin:0;color:#0f172a}.cj-helper-head p{font-size:13px;color:#64748b;margin:4px 0 0}.cj-close-btn{width:34px;height:34px;border:1px solid var(--bdp-border);background:#fff;border-radius:12px;cursor:pointer;color:#475569}.cj-workflow{display:grid;gap:9px}.cj-step{display:flex;align-items:flex-start;gap:11px;padding:12px;border:1px solid #edf1f7;border-radius:13px;background:#fbfcff}.cj-step-number{width:28px;height:28px;border-radius:10px;background:#eef3ff;color:#1e2a44;display:grid;place-items:center;font-weight:850;flex:0 0 auto}.cj-step strong{display:block;color:#0f172a}.cj-step small{display:block;color:#64748b;margin-top:2px;line-height:1.35}.cj-tip-list{display:grid;gap:10px}.cj-tip{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid #edf1f7;border-radius:13px;background:#fff}.cj-tip span{font-weight:750}.cj-tip small{color:#64748b}.cj-mini-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:850;background:#f1f5f9;color:#475569;white-space:nowrap}.cj-mini-pill.green{background:#dcfce7;color:#166534}.cj-mini-pill.orange{background:#ffedd5;color:#9a3412}.cj-mini-pill.blue{background:#dbeafe;color:#1d4ed8}.cj-compact-note{font-size:12px;color:#64748b;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:10px 12px;margin-top:12px}


    .cj-pagination-wrap{display:flex;align-items:center;justify-content:center;gap:8px;margin:22px 0 4px;flex-wrap:wrap}
    .cj-page-link{min-width:36px;height:36px;padding:0 12px;border:1px solid var(--bdp-border);background:#fff;color:#0f172a;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;text-decoration:none;box-shadow:0 4px 12px rgba(15,23,42,.035)}
    .cj-page-link:hover{border-color:#cbd5e1;background:#f8fafc;color:#0f172a;text-decoration:none}
    .cj-page-link.is-active{background:#ff6b1a;border-color:#ff6b1a;color:#fff}
    .cj-page-link.is-disabled{opacity:.45;pointer-events:none;background:#f8fafc;color:#64748b}
    .cj-page-ellipsis{height:36px;min-width:28px;display:inline-flex;align-items:center;justify-content:center;color:#64748b;font-weight:900}
    .cj-pagination-summary{width:100%;text-align:center;font-size:12px;color:#64748b;margin-top:4px}

    .cj-bulk-bar{display:none;align-items:center;justify-content:space-between;gap:12px;margin:12px 0 16px;padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;color:#1e3a8a}.cj-bulk-bar.is-active{display:flex}.cj-bulk-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.cj-selected-count{font-weight:900}.cj-card-top{position:absolute;inset:10px 10px auto 10px;display:flex;align-items:center;justify-content:space-between;gap:8px;z-index:2}.cj-select-wrap{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.92);border:1px solid rgba(226,232,240,.95);box-shadow:0 6px 18px rgba(15,23,42,.12);border-radius:999px;padding:6px 9px;font-size:12px;font-weight:850;color:#0f172a;backdrop-filter:blur(8px)}.cj-select-wrap input{width:15px;height:15px;accent-color:#ff6b1a}.cj-preview-btn{border:1px solid rgba(226,232,240,.95);background:rgba(255,255,255,.92);border-radius:999px;padding:7px 10px;font-size:12px;font-weight:850;color:#0f172a;box-shadow:0 6px 18px rgba(15,23,42,.12);cursor:pointer;backdrop-filter:blur(8px)}.cj-preview-btn:hover{background:#fff;border-color:#cbd5e1}.cj-product-card.is-selected{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.14),0 10px 26px rgba(15,23,42,.08)}.cj-card-actions{display:flex;align-items:center;gap:8px}.cj-table-check{width:18px;height:18px;accent-color:#ff6b1a}.cj-table-actions{display:flex;align-items:center;gap:8px;justify-content:flex-end}.cj-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.48);z-index:120}.cj-modal-backdrop.is-open{display:block}.cj-preview-modal{display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(940px,calc(100vw - 34px));max-height:calc(100vh - 54px);overflow:auto;background:#fff;border:1px solid var(--bdp-border);border-radius:22px;box-shadow:0 30px 90px rgba(15,23,42,.32);z-index:130}.cj-preview-modal.is-open{display:block}.cj-preview-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:18px 20px;border-bottom:1px solid #edf1f7}.cj-preview-modal-head h3{margin:0;font-size:20px;color:#0f172a}.cj-preview-modal-head p{margin:4px 0 0;color:#64748b;font-size:13px}.cj-modal-close{width:36px;height:36px;border:1px solid var(--bdp-border);border-radius:13px;background:#fff;cursor:pointer;font-size:22px;line-height:1;color:#475569}.cj-preview-modal-body{display:grid;grid-template-columns:minmax(280px,380px) 1fr;gap:18px;padding:20px}.cj-preview-main-img{border:1px solid var(--bdp-border);border-radius:18px;background:#f8fafc;overflow:hidden;aspect-ratio:1/1;display:grid;place-items:center}.cj-preview-main-img img{width:100%;height:100%;object-fit:cover}.cj-preview-details{display:grid;gap:12px;align-content:start}.cj-preview-title{font-size:22px;font-weight:900;color:#0f172a;line-height:1.2}.cj-preview-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.cj-preview-stat{border:1px solid #edf1f7;background:#fbfcff;border-radius:14px;padding:11px}.cj-preview-stat span{display:block;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:850}.cj-preview-stat strong{display:block;margin-top:4px;color:#0f172a;font-size:14px;word-break:break-word}.cj-preview-note{font-size:13px;line-height:1.45;color:#64748b;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:14px;padding:12px}.cj-preview-modal-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px}.cj-small-muted{font-size:12px;color:#64748b}.cj-form-inline{display:inline}.cj-results-tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}.cj-mini-btn{font-size:12px;padding:8px 10px}.cj-table-select-col{width:38px}

    @media(max-width:1400px){.cj-product-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.cj-search-compact{grid-template-columns:2fr 1.2fr .7fr .7fr .7fr .9fr auto;}.cj-search-compact .reset-action{display:none}}
    @media(max-width:1100px){.cj-product-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cj-search-compact,.cj-direct-grid{grid-template-columns:1fr 1fr}.cj-search-compact .search-keyword{grid-column:1/-1}.cj-search-compact .search-action,.cj-search-compact .reset-action{grid-column:auto}.cj-span-6,.cj-span-4,.cj-span-3,.cj-span-2{grid-column:span 6}}
    @media(max-width:700px){.cj-preview-modal-body{grid-template-columns:1fr}.cj-preview-grid{grid-template-columns:1fr}.cj-bulk-bar{display:none}.cj-bulk-bar.is-active{display:block}.cj-results-tools{justify-content:flex-start}.cj-hero{display:block}.cj-hero-actions{justify-content:flex-start;margin-top:12px}.cj-product-grid{grid-template-columns:1fr}.cj-form-grid{grid-template-columns:1fr}.cj-span-12,.cj-span-6,.cj-span-4,.cj-span-3,.cj-span-2{grid-column:span 1}.cj-help-row,.cj-results-head{display:block}.cj-view-toggle{margin-top:12px}.cj-mobile-only{display:block}.cj-search-compact,.cj-direct-grid{grid-template-columns:1fr}.cj-helper-popover{right:16px;top:68px}}
</style>

<div class="cj-hero">
    <div>
        <h1>CJ API Import</h1>
        <p>Search CJ products or import directly by product ID / SKU. Imported products stay as drafts in Data Pilot so the team can review SEO, pricing, category, images, shipping, stock, and variations before syncing to Balenco.</p>
    </div>
    <div class="cj-hero-actions">
        <button class="bdp-btn light" type="button" data-helper-open="hunter-flow">Product Hunter Flow</button>
        <button class="bdp-btn light" type="button" data-helper-open="import-checklist">Import Checklist</button>
    </div>
</div>

<div class="cj-helper-backdrop" data-helper-close></div>
<div class="cj-helper-popover" id="hunter-flow-panel" aria-hidden="true">
    <div class="cj-helper-head">
        <div><h3>Product Hunter Flow</h3><p>Keep quality control before publishing.</p></div>
        <button class="cj-close-btn" type="button" data-helper-close>×</button>
    </div>
    <div class="cj-workflow">
        <div class="cj-step"><div class="cj-step-number">1</div><div><strong>Search or paste CJ product</strong><small>Find the product by keyword, PID, SPU, or variant SKU.</small></div></div>
        <div class="cj-step"><div class="cj-step-number">2</div><div><strong>Import to Data Pilot</strong><small>Store raw CJ data, images, variants, stock, price, and shipping fields.</small></div></div>
        <div class="cj-step"><div class="cj-step-number">3</div><div><strong>Review and optimize</strong><small>SEO manager cleans title, description, category, pricing, and images.</small></div></div>
        <div class="cj-step"><div class="cj-step-number">4</div><div><strong>Sync to Balenco</strong><small>Push as draft first, check product page, then publish.</small></div></div>
    </div>
</div>
<div class="cj-helper-popover" id="import-checklist-panel" aria-hidden="true">
    <div class="cj-helper-head">
        <div><h3>Import Checklist</h3><p>Use this before choosing final products.</p></div>
        <button class="cj-close-btn" type="button" data-helper-close>×</button>
    </div>
    <div class="cj-tip-list">
        <div class="cj-tip"><div><span>Real product images</span><br><small>Avoid products with weak gallery images.</small></div><em class="cj-mini-pill green">Required</em></div>
        <div class="cj-tip"><div><span>Clear variants</span><br><small>Color and size should be understandable.</small></div><em class="cj-mini-pill blue">Important</em></div>
        <div class="cj-tip"><div><span>Good margin potential</span><br><small>Check landed cost + shipping before syncing.</small></div><em class="cj-mini-pill orange">Review</em></div>
        <div class="cj-tip"><div><span>Relevant category</span><br><small>Match to Balenco footwear categories.</small></div><em class="cj-mini-pill">Map later</em></div>
    </div>
    <div class="cj-compact-note">Keep this closed while searching. Open it only when choosing final products for import.</div>
</div>


<div class="cj-modal-backdrop" data-preview-close></div>
<div class="cj-preview-modal" id="cj-preview-modal" aria-hidden="true">
    <div class="cj-preview-modal-head">
        <div>
            <h3>Product Preview</h3>
            <p>Check images, SKU, category, and price before importing to Data Pilot.</p>
        </div>
        <button class="cj-modal-close" type="button" data-preview-close>×</button>
    </div>
    <div class="cj-preview-modal-body">
        <div class="cj-preview-main-img"><img id="cj-preview-image" src="" alt="CJ product preview"></div>
        <div class="cj-preview-details">
            <div class="cj-preview-title" id="cj-preview-title">CJ Product</div>
            <div class="cj-preview-grid">
                <div class="cj-preview-stat"><span>SKU / SPU</span><strong id="cj-preview-sku">—</strong></div>
                <div class="cj-preview-stat"><span>Product ID / PID</span><strong id="cj-preview-pid">—</strong></div>
                <div class="cj-preview-stat"><span>CJ Price</span><strong id="cj-preview-price">—</strong></div>
                <div class="cj-preview-stat"><span>Category</span><strong id="cj-preview-category">—</strong></div>
            </div>
            <div class="cj-preview-note">Imported products are saved as drafts in Data Pilot. After import, refresh shipping, clean SEO, review images/variants, then push to Balenco as draft.</div>
            <div class="cj-preview-modal-actions">
                <form method="POST" action="{{ route('bdp.cj.import.product') }}" id="cj-preview-import-form" class="cj-form-inline">
                    @csrf
                    <input type="hidden" name="pid" id="cj-preview-import-pid">
                    <input type="hidden" name="product_sku" id="cj-preview-import-sku">
                    <button class="bdp-btn orange" type="submit">Import this product</button>
                </form>
                <button class="bdp-btn light" type="button" data-preview-close>Close preview</button>
            </div>
        </div>
    </div>
</div>

<div class="cj-layout">
    <div>
        <div class="cj-panel search-panel">
            <div class="cj-panel-head">
                <div>
                    <h2>Search CJ Products</h2>
                    <p class="muted">Use broad buyer-intent keywords first, then narrow by warehouse and price.</p>
                </div>
                <a class="bdp-btn light" href="{{ route('bdp.api.logs') }}">View API Logs</a>
            </div>

            <form method="GET" action="{{ route('bdp.cj.import') }}" class="cj-search-compact">
                <div class="search-keyword">
                    <label>Search keyword</label>
                    <input type="text" name="keyword" value="{{ request('keyword', $keyword ?? '') }}" placeholder="women pumps, sneakers, heels">
                </div>
                <div>
                    <label>Warehouse</label>
                    <select name="country_code">
                        <option value="">All warehouses</option>
                        @foreach(['US' => 'United States', 'CN' => 'China', 'GB' => 'United Kingdom', 'DE' => 'Germany'] as $code => $label)
                            <option value="{{ $code }}" @selected(request('country_code') === $code)>{{ $code }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Min</label>
                    <input type="text" name="min_price" value="{{ request('min_price') }}" placeholder="10">
                </div>
                <div>
                    <label>Max</label>
                    <input type="text" name="max_price" value="{{ request('max_price') }}" placeholder="80">
                </div>
                <div>
                    <label>Size</label>
                    <select name="size">
                        @foreach([10,20,50,100] as $size)
                            <option value="{{ $size }}" @selected((int) request('size', 20) === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>View</label>
                    <select name="view">
                        <option value="grid" @selected(request('view', 'grid') === 'grid')>Grid cards</option>
                        <option value="list" @selected(request('view') === 'list')>List table</option>
                    </select>
                </div>
                <div class="search-action">
                    <button class="bdp-btn orange" type="submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        Search
                    </button>
                </div>
                <div class="reset-action">
                    <a class="bdp-btn light" href="{{ route('bdp.cj.import') }}">Reset</a>
                </div>
            </form>
        </div>

        <div class="cj-panel import-panel cj-accordion" data-direct-import>
            <button type="button" class="cj-accordion-head" aria-expanded="false">
                <span class="cj-accordion-title">
                    <h2>Import directly by Product ID / SKU</h2>
                    <p>Use this only when a product hunter already has a CJ PID, SPU, or variant SKU.</p>
                </span>
                <span class="cj-accordion-chevron">⌄</span>
            </button>
            <div class="cj-accordion-body">
                <form method="POST" action="{{ route('bdp.cj.import.product') }}" class="cj-direct-grid">
                    @csrf
                    <div>
                        <label>CJ Product ID / PID</label>
                        <input type="text" name="pid" placeholder="000B9312-456A-4D31-94BD-B083E2A198E8">
                    </div>
                    <div>
                        <label>Product SKU / SPU</label>
                        <input type="text" name="product_sku" placeholder="CJNS...">
                    </div>
                    <div>
                        <label>Variant SKU</label>
                        <input type="text" name="variant_sku" placeholder="Optional">
                    </div>
                    <div>
                        <button class="bdp-btn" type="submit">Import Product</button>
                    </div>
                </form>
                <div class="bdp-input-help" style="margin-top:10px">Imported products are saved as drafts for review before Balenco sync.</div>
            </div>
        </div>
    </div>


</div>

@if($results)
    @php
        $products = $results['bdp_products'] ?? [];
        $source = $results['bdp_search_source'] ?? 'CJ API';
        $totalRecords = data_get($results, 'data.totalRecords') ?? data_get($results, 'data.total') ?? data_get($results, 'data.count');
        $message = $results['message'] ?? '';
        $viewMode = request('view', 'grid');
    @endphp
    <div class="cj-panel cj-results-panel">
        <div class="cj-results-head">
            <div>
                <h2>Search Results</h2>
                <div class="cj-results-meta">
                    <span class="cj-mini-pill blue">Source: {{ $source }}</span>
                    @if($totalRecords !== null)<span class="cj-mini-pill">Total: {{ $totalRecords }}</span>@endif
                    @if($message)<span class="cj-mini-pill orange">{{ $message }}</span>@endif
                    <span class="cj-mini-pill green">{{ count($products) }} shown</span>
                </div>
            </div>
            <div class="cj-results-tools">
                @if(count($products))
                    <button class="bdp-btn light cj-mini-btn" type="button" data-cj-select-all>Select all visible</button>
                    <button class="bdp-btn light cj-mini-btn" type="button" data-cj-clear-selection>Clear</button>
                @endif
                <div class="cj-view-toggle">
                    <a class="bdp-btn {{ $viewMode === 'grid' ? 'orange' : 'light' }}" href="{{ request()->fullUrlWithQuery(['view' => 'grid']) }}">Grid View</a>
                    <a class="bdp-btn {{ $viewMode === 'list' ? 'orange' : 'light' }}" href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}">List View</a>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('bdp.cj.import.bulk') }}" id="bulk-cj-import-form">
            @csrf
        </form>
        <div class="cj-bulk-bar" data-cj-bulk-bar>
            <div class="cj-bulk-left">
                <span class="cj-selected-count" data-cj-selected-count>0 selected</span>
                <span class="cj-small-muted">Selected products will be imported as drafts into Data Pilot.</span>
            </div>
            <button class="bdp-btn orange" type="submit" form="bulk-cj-import-form" data-cj-bulk-submit disabled>Import selected</button>
        </div>

        @if(!empty($results['bdp_first_response']))
            <div class="bdp-alert success" style="background:#f8fafc;color:#475569;border-color:#e2e8f0">
                First attempt: {{ $results['bdp_first_response']['source'] ?? '' }} returned total {{ $results['bdp_first_response']['totalRecords'] ?? '0' }}. Fallback was used.
            </div>
        @endif

        @if($viewMode === 'grid')
            <div class="cj-product-grid">
                @forelse($products as $item)
                    @php
                        $pid = $item['id'] ?? $item['pid'] ?? '';
                        $sku = $item['sku'] ?? $item['spu'] ?? $item['productSku'] ?? '';
                        $name = $item['nameEn'] ?? $item['productNameEn'] ?? $item['productName'] ?? 'CJ Product';
                        $image = $item['bigImage'] ?? $item['productImage'] ?? '';
                        $price = $item['sellPrice'] ?? $item['nowPrice'] ?? $item['discountPrice'] ?? '';
                        $cat = $item['threeCategoryName'] ?? $item['categoryName'] ?? $item['twoCategoryName'] ?? '';
                        $bulkValue = base64_encode(json_encode(['pid' => (string) $pid, 'product_sku' => (string) $sku]));
                    @endphp
                    <div class="cj-product-card">
                        <div class="cj-product-image">
                            <div class="cj-card-top">
                                <label class="cj-select-wrap"><input type="checkbox" class="cj-select-product" form="bulk-cj-import-form" name="items[]" value="{{ $bulkValue }}"> Select</label>
                                <button class="cj-preview-btn" type="button" data-preview-product data-name="{{ e($name) }}" data-image="{{ e($image) }}" data-sku="{{ e($sku) }}" data-pid="{{ e($pid) }}" data-price="{{ e($price) }}" data-category="{{ e($cat) }}">Preview</button>
                            </div>
                            @if($image)<img src="{{ $image }}" alt="{{ $name }}">@else<span class="cj-no-img">No image</span>@endif
                        </div>
                        <div class="cj-product-body">
                            <div class="cj-product-title">{{ $name }}</div>
                            <div class="cj-product-meta">
                                <div><b>SKU:</b> {{ $sku ?: '—' }}</div>
                                <div><b>PID:</b> {{ $pid ?: '—' }}</div>
                                <div><b>Category:</b> {{ $cat ?: '—' }}</div>
                                <div><b>Shipping:</b> refresh after import</div>
                            </div>
                            <div class="cj-product-footer">
                                <div class="cj-price">{{ $price !== '' ? '$'.$price : '—' }}</div>
                                <form method="POST" action="{{ route('bdp.cj.import.product') }}">
                                    @csrf
                                    <input type="hidden" name="pid" value="{{ $pid }}">
                                    <input type="hidden" name="product_sku" value="{{ $sku }}">
                                    <button class="bdp-btn light" type="submit">Import</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="cj-empty" style="grid-column:1/-1">
                        <h3>No CJ products found</h3>
                        <p>Try a broader keyword like <strong>shoes</strong>, <strong>heels</strong>, or leave price/country empty. You can also check API Logs for the raw CJ response.</p>
                        <a class="bdp-btn orange" href="{{ route('bdp.cj.import') }}">Reset Search</a>
                    </div>
                @endforelse
            </div>
        @else
            <div class="bdp-table-wrap">
                <table class="bdp-table">
                    <thead><tr><th class="cj-table-select-col"></th><th>Product</th><th>SKU / PID</th><th>CJ Cost</th><th>Category</th><th>Shipping</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($products as $item)
                        @php
                            $pid = $item['id'] ?? $item['pid'] ?? '';
                            $sku = $item['sku'] ?? $item['spu'] ?? $item['productSku'] ?? '';
                            $name = $item['nameEn'] ?? $item['productNameEn'] ?? $item['productName'] ?? 'CJ Product';
                            $image = $item['bigImage'] ?? $item['productImage'] ?? '';
                            $price = $item['sellPrice'] ?? $item['nowPrice'] ?? $item['discountPrice'] ?? '';
                            $cat = $item['threeCategoryName'] ?? $item['categoryName'] ?? $item['twoCategoryName'] ?? '';
                            $bulkValue = base64_encode(json_encode(['pid' => (string) $pid, 'product_sku' => (string) $sku]));
                        @endphp
                        <tr>
                            <td><input type="checkbox" class="cj-table-check cj-select-product" form="bulk-cj-import-form" name="items[]" value="{{ $bulkValue }}"></td>
                            <td>
                                <div class="cj-table-product">
                                    @if($image)<img src="{{ $image }}" alt="{{ $name }}">@endif
                                    <div><div class="cj-table-title">{{ $name }}</div><div class="cj-table-sub">PID: {{ $pid ?: '—' }}</div></div>
                                </div>
                            </td>
                            <td><strong>{{ $sku ?: '—' }}</strong><br><small>{{ $pid ?: '' }}</small></td>
                            <td>{{ $price !== '' ? '$'.$price : '-' }}</td>
                            <td>{{ $cat ?: '—' }}</td>
                            <td><small>Shown after import + Refresh CJ Shipping</small></td>
                            <td>
                                <div class="cj-table-actions">
                                    <button class="bdp-btn light cj-mini-btn" type="button" data-preview-product data-name="{{ e($name) }}" data-image="{{ e($image) }}" data-sku="{{ e($sku) }}" data-pid="{{ e($pid) }}" data-price="{{ e($price) }}" data-category="{{ e($cat) }}">Preview</button>
                                    <form method="POST" action="{{ route('bdp.cj.import.product') }}">
                                        @csrf
                                        <input type="hidden" name="pid" value="{{ $pid }}">
                                        <input type="hidden" name="product_sku" value="{{ $sku }}">
                                        <button class="bdp-btn light cj-mini-btn" type="submit">Import</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No CJ products found for this search. Try a broader keyword or check API Logs.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        @php
            $currentPage = max(1, (int) request('page', 1));
            $pageSize = max(1, (int) request('size', 20));
            $totalForPagination = is_numeric($totalRecords ?? null) ? (int) $totalRecords : null;
            $totalPages = $totalForPagination ? (int) ceil($totalForPagination / $pageSize) : null;
            $shownCount = count($products);
            $pageStart = $shownCount ? (($currentPage - 1) * $pageSize) + 1 : 0;
            $pageEnd = $shownCount ? $pageStart + $shownCount - 1 : 0;

            $paginationPages = [];
            if ($totalPages) {
                $windowStart = max(1, $currentPage - 2);
                $windowEnd = min($totalPages, $currentPage + 2);
                $paginationPages[] = 1;
                for ($i = $windowStart; $i <= $windowEnd; $i++) {
                    $paginationPages[] = $i;
                }
                if ($totalPages > 1) {
                    $paginationPages[] = $totalPages;
                }
                $paginationPages = array_values(array_unique(array_filter($paginationPages, fn ($page) => $page >= 1 && $page <= $totalPages)));
                sort($paginationPages);
            }
        @endphp

        @if($totalPages && $totalPages > 1)
            <nav class="cj-pagination-wrap" aria-label="CJ product result pages">
                <a class="cj-page-link {{ $currentPage <= 1 ? 'is-disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => max(1, $currentPage - 1)]) }}">‹ Prev</a>

                @php $lastRenderedPage = 0; @endphp
                @foreach($paginationPages as $pageNumber)
                    @if($lastRenderedPage && $pageNumber > $lastRenderedPage + 1)
                        <span class="cj-page-ellipsis">…</span>
                    @endif
                    <a class="cj-page-link {{ $pageNumber === $currentPage ? 'is-active' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => $pageNumber]) }}">{{ $pageNumber }}</a>
                    @php $lastRenderedPage = $pageNumber; @endphp
                @endforeach

                <a class="cj-page-link {{ $currentPage >= $totalPages ? 'is-disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => min($totalPages, $currentPage + 1)]) }}">Next ›</a>
                <div class="cj-pagination-summary">Showing {{ number_format($pageStart) }}–{{ number_format($pageEnd) }} of {{ number_format($totalForPagination) }} CJ results</div>
            </nav>
        @elseif($shownCount >= $pageSize)
            <nav class="cj-pagination-wrap" aria-label="CJ product result pages">
                <a class="cj-page-link {{ $currentPage <= 1 ? 'is-disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => max(1, $currentPage - 1)]) }}">‹ Prev</a>
                <a class="cj-page-link is-active" href="{{ request()->fullUrlWithQuery(['page' => $currentPage]) }}">{{ $currentPage }}</a>
                <a class="cj-page-link" href="{{ request()->fullUrlWithQuery(['page' => $currentPage + 1]) }}">Next ›</a>
                <div class="cj-pagination-summary">Showing page {{ number_format($currentPage) }}. CJ did not return a total count for this search.</div>
            </nav>
        @endif

        <div class="cj-help-row">
            <span>Imported products are saved as drafts in Data Pilot. Run shipping refresh and SEO cleanup before syncing.</span>
            <a href="{{ route('bdp.products') }}">Open Products →</a>
        </div>
    </div>
@else
    <div class="cj-panel cj-results-panel">
        <div class="cj-empty">
            <h3>Search CJ to start importing products</h3>
            <p>Use the search form above to find women’s footwear products, or paste a CJ product ID / SKU if you already have one.</p>
            <div class="cj-inline-actions" style="justify-content:center">
                <a class="bdp-btn orange" href="{{ route('bdp.cj.import', ['keyword' => 'women sneakers']) }}">Try “women sneakers”</a>
                <a class="bdp-btn light" href="{{ route('bdp.cj.settings') }}">Check CJ Settings</a>
            </div>
        </div>
    </div>
@endif

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const backdrop = document.querySelector('.cj-helper-backdrop');
        const panels = {
            'hunter-flow': document.getElementById('hunter-flow-panel'),
            'import-checklist': document.getElementById('import-checklist-panel')
        };

        function closeHelpers() {
            Object.values(panels).forEach(function (panel) {
                panel?.classList.remove('is-open');
                panel?.setAttribute('aria-hidden', 'true');
            });
            backdrop?.classList.remove('is-open');
        }

        document.querySelectorAll('[data-helper-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                const key = button.dataset.helperOpen;
                const panel = panels[key];
                const isOpen = panel?.classList.contains('is-open');
                closeHelpers();
                if (!isOpen && panel) {
                    panel.classList.add('is-open');
                    panel.setAttribute('aria-hidden', 'false');
                    backdrop?.classList.add('is-open');
                }
            });
        });

        document.querySelectorAll('[data-helper-close]').forEach(function (el) {
            el.addEventListener('click', closeHelpers);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeHelpers(); closePreview(); }
        });



        const previewModal = document.getElementById('cj-preview-modal');
        const previewBackdrop = document.querySelector('.cj-modal-backdrop');
        const previewImage = document.getElementById('cj-preview-image');
        const previewTitle = document.getElementById('cj-preview-title');
        const previewSku = document.getElementById('cj-preview-sku');
        const previewPid = document.getElementById('cj-preview-pid');
        const previewPrice = document.getElementById('cj-preview-price');
        const previewCategory = document.getElementById('cj-preview-category');
        const previewImportPid = document.getElementById('cj-preview-import-pid');
        const previewImportSku = document.getElementById('cj-preview-import-sku');

        function openPreview(button) {
            const price = button.dataset.price ? ('$' + button.dataset.price) : '—';
            previewImage.src = button.dataset.image || '';
            previewImage.alt = button.dataset.name || 'CJ product preview';
            previewTitle.textContent = button.dataset.name || 'CJ Product';
            previewSku.textContent = button.dataset.sku || '—';
            previewPid.textContent = button.dataset.pid || '—';
            previewPrice.textContent = price;
            previewCategory.textContent = button.dataset.category || '—';
            previewImportPid.value = button.dataset.pid || '';
            previewImportSku.value = button.dataset.sku || '';
            previewModal?.classList.add('is-open');
            previewModal?.setAttribute('aria-hidden', 'false');
            previewBackdrop?.classList.add('is-open');
        }

        function closePreview() {
            previewModal?.classList.remove('is-open');
            previewModal?.setAttribute('aria-hidden', 'true');
            previewBackdrop?.classList.remove('is-open');
        }

        document.querySelectorAll('[data-preview-product]').forEach(function (button) {
            button.addEventListener('click', function () { openPreview(button); });
        });
        document.querySelectorAll('[data-preview-close]').forEach(function (el) {
            el.addEventListener('click', closePreview);
        });

        const selectedCount = document.querySelector('[data-cj-selected-count]');
        const bulkBar = document.querySelector('[data-cj-bulk-bar]');
        const bulkSubmit = document.querySelector('[data-cj-bulk-submit]');
        const productChecks = Array.from(document.querySelectorAll('.cj-select-product'));

        function updateSelection() {
            const count = productChecks.filter(function (input) { return input.checked; }).length;
            if (selectedCount) selectedCount.textContent = count + (count === 1 ? ' selected' : ' selected');
            bulkBar?.classList.toggle('is-active', count > 0);
            if (bulkSubmit) bulkSubmit.disabled = count === 0;
            productChecks.forEach(function (input) {
                input.closest('.cj-product-card')?.classList.toggle('is-selected', input.checked);
            });
        }

        productChecks.forEach(function (input) {
            input.addEventListener('change', updateSelection);
        });

        document.querySelector('[data-cj-select-all]')?.addEventListener('click', function () {
            productChecks.forEach(function (input) { input.checked = true; });
            updateSelection();
        });
        document.querySelector('[data-cj-clear-selection]')?.addEventListener('click', function () {
            productChecks.forEach(function (input) { input.checked = false; });
            updateSelection();
        });

        document.getElementById('bulk-cj-import-form')?.addEventListener('submit', function (event) {
            const count = productChecks.filter(function (input) { return input.checked; }).length;
            if (count === 0) {
                event.preventDefault();
                alert('Please select at least one product to import.');
                return;
            }
            if (!confirm('Import ' + count + ' selected product(s) into Data Pilot as drafts?')) {
                event.preventDefault();
            }
        });

        document.querySelectorAll('[data-direct-import]').forEach(function (panel) {
            const key = 'bdp:v4:cj-direct-import';
            const button = panel.querySelector('.cj-accordion-head');
            function setOpen(open) {
                panel.classList.toggle('is-open', open);
                button?.setAttribute('aria-expanded', open ? 'true' : 'false');
                localStorage.setItem(key, open ? '1' : '0');
            }
            setOpen(localStorage.getItem(key) === '1');
            button?.addEventListener('click', function () {
                setOpen(!panel.classList.contains('is-open'));
            });
        });
    });
</script>

@endsection
