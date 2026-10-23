@extends('saas.layout')
@section('title', 'Edit product')
@push('styles')
<style>
.catalog-editor{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px;align-items:start}
.catalog-editor-main{display:grid;gap:14px;min-width:0}
.catalog-editor-side{display:grid;gap:14px;position:sticky;top:14px}
.catalog-editor-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.catalog-editor-section{scroll-margin-top:16px}
.catalog-editor-nav{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.catalog-editor-nav a{border:1px solid #d6dfea;padding:8px 13px;border-radius:999px;font-size:12px;font-weight:750;background:#fff}
.catalog-editor-nav a:hover{background:#edf2ff}
.catalog-field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.catalog-field-grid .wide{grid-column:1/-1}
.catalog-field-grid label{display:block;font-size:13px;font-weight:750;margin-bottom:5px}
.catalog-field-grid input,.catalog-field-grid textarea{width:100%;min-width:0;border:1px solid #cbd5e1;background:#fff;border-radius:9px;color:var(--ink);padding:10px 12px;font:inherit}
.catalog-field-grid input{height:43px}
.catalog-field-grid textarea{min-height:95px;resize:vertical}
.catalog-field-grid small{display:block;font-size:12px;color:#6b7280;margin-top:6px}
.catalog-editor .card{padding:19px}
.catalog-editor .card h2{font-size:20px;margin-bottom:5px}
.catalog-note{font-size:12px;color:#475569;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px}
.catalog-source-image{max-height:270px;width:100%;object-fit:contain;border-radius:12px;background:#f8fafc}
.catalog-gallery{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px}
.catalog-gallery img{width:100%;aspect-ratio:1;object-fit:contain;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb}
.catalog-source-rows{display:grid;gap:10px}
.catalog-source-rows div{border-bottom:1px solid #e5eaf2;padding-bottom:9px;overflow-wrap:anywhere}
.catalog-source-rows span{font-size:11px;display:block;font-weight:800;color:#67758c;text-transform:uppercase}
.catalog-var-table td,.catalog-var-table th{white-space:nowrap;font-size:12px;vertical-align:middle}
.catalog-var-img{width:45px;height:45px;object-fit:contain;border-radius:7px}
.catalog-editor-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:13px 0 20px}
.catalog-rich{border:1px solid #cbd5e1;border-radius:11px;overflow:hidden}
.catalog-rich-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:5px;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:8px}
.catalog-rich-toolbar button{min-width:35px;height:32px;border:1px solid #cbd5e1;border-radius:7px;background:white;color:#17213c;font-weight:700}
.catalog-rich-canvas{min-height:155px;padding:13px;outline-offset:-3px;overflow:auto;line-height:1.65}
.catalog-rich-canvas:empty:before{content:attr(data-placeholder);color:#94a3b8}
.catalog-rich-canvas img{max-width:100%;height:auto}
.catalog-rich-source{width:100%;min-height:180px;border:0;padding:12px;font:13px/1.5 ui-monospace,monospace;resize:vertical}
.catalog-rich-footer{padding:7px 10px;font-size:11px;color:#64748b;border-top:1px solid #e5e7eb}
@media(max-width:1050px){.catalog-editor{grid-template-columns:minmax(0,1fr) 280px}}
@media(max-width:850px){.catalog-editor{grid-template-columns:1fr}.catalog-editor-side{position:static}}
@media(max-width:560px){.catalog-field-grid{grid-template-columns:1fr}.catalog-field-grid .wide{grid-column:auto}.catalog-editor .card{padding:14px}}
</style>
@endpush
@section('content')
@php
    $safeHtml = app(\App\Services\Saas\SaasProductHtmlSanitizer::class);
    $shortValue = $safeHtml->clean(mb_substr((string) old('short_description_html', $product->short_description_html), 0, 60000)) ?? '';
    $longValue = $safeHtml->clean(mb_substr((string) old('long_description_html', $product->long_description_html), 0, 60000)) ?? '';
    $gallery = collect($product->gallery_images ?? [])->filter(fn ($url) => $safeHtml->safeUrl($url))->take(20);
    $image = $product->featured_image_url ?: data_get($product->source_snapshot, 'image_url');
    $variants = $product->source_variants ?? [];
    $checks = [
        'Title' => filled($product->title),
        'SKU' => filled($product->sku),
        'Category' => filled($product->category_name),
        'Slug' => filled($product->slug),
        'Short description' => filled($product->short_description_html),
        'Long description' => filled($product->long_description_html),
        'SEO title' => filled($product->seo_title),
        'SEO description' => filled($product->seo_description),
        'Selling price' => filled($product->selling_price_usd),
        'Gallery' => count($product->gallery_images ?? []) > 0,
        'Variants' => count($variants) > 0,
    ];
    $ready = collect($checks)->filter()->count();
@endphp
<div class="page-title">
    <a class="micro" href="{{ route('saas.workspace.products', $workspace->public_id) }}">← My Products</a>
    <div class="catalog-editor-head" style="margin-top:7px">
        <div><span class="eyebrow">Product catalog · Merchant editor</span><h1 style="margin:3px 0 4px">Edit product</h1>
            <p style="margin:0">Review CJ data, edit HTML content, SEO, pricing, and images before publishing.</p></div>
        <span class="tag">Draft · Not published</span>
    </div>
</div>
@if($errors->any())
<div class="alert" role="alert">
    <strong>Some changes could not be applied.</strong>
    <ul style="margin:6px 0 0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
</div>
@endif

<nav class="catalog-editor-nav" aria-label="Product edit sections">
    <a href="#content">Content</a><a href="#seo">SEO</a><a href="#pricing">Pricing</a>
    <a href="#images">Images</a><a href="#variants">Variations</a><a href="#publishing">Publishing</a>
</nav>

<div class="catalog-editor">
    <div class="catalog-editor-main">
        <form id="customer-product-form" method="post" action="{{ route('saas.workspace.products.update', ['workspace'=>$workspace->public_id,'product'=>$product->id]) }}">
        @csrf
        @method('PATCH')
        <section id="content" class="card catalog-editor-section">
            <div class="split"><div><h2>Product content</h2><p class="micro">Title, slug, categories, tags, and short/long HTML descriptions.</p></div><span class="tag">{{ filled($product->long_description_html) ? 'Description imported' : 'Needs description' }}</span></div>
            <div class="catalog-field-grid">
                <div class="wide"><label for="prod_title">Product title *</label>
                    <input id="prod_title" name="title" required maxlength="250" value="{{ old('title', $product->title) }}">
                    @error('title')<span class="error">{{ $message }}</span>@enderror</div>
                <div><label for="prod_slug">URL slug</label><input id="prod_slug" name="slug" maxlength="255" placeholder="clean-product-url" value="{{ old('slug', $product->slug) }}"></div>
                <div><label for="prod_sku">SKU</label><input id="prod_sku" name="sku" maxlength="180" value="{{ old('sku', $product->sku) }}"></div>
                <div><label for="prod_category">Category</label><input id="prod_category" name="category_name" maxlength="200" value="{{ old('category_name', $product->category_name ?? data_get($product->source_snapshot,'category')) }}"></div>
                <div><label for="prod_tags">Tags (comma-separated)</label><input id="prod_tags" name="tags" maxlength="1000" placeholder="table linen, home decor" value="{{ old('tags', $product->tags) }}"></div>
                <div class="wide">
                    <label for="short_html_editor">Short description — rich HTML</label>
                    <div class="catalog-rich" data-rich-editor>
                        <div class="catalog-rich-toolbar" role="toolbar" aria-label="Short description formatting">
                            <button type="button" data-command="bold" title="Bold"><strong>B</strong></button>
                            <button type="button" data-command="italic" title="Italic"><em>I</em></button>
                            <button type="button" data-command="insertUnorderedList" title="Bulleted list">• List</button>
                            <button type="button" data-command="insertOrderedList" title="Numbered list">1. List</button>
                            <button type="button" data-source-toggle>HTML source</button>
                        </div>
                        <div id="short_html_editor" role="textbox" aria-multiline="true" contenteditable="true" class="catalog-rich-canvas" data-canvas data-placeholder="Buyer-friendly summary and key benefits">{!! $shortValue !!}</div>
                        <textarea hidden class="catalog-rich-source" data-html-source name="short_description_html">{{ $shortValue }}</textarea>
                        <div class="catalog-rich-footer">Basic formatting or HTML source. Unsafe tags, scripts, and event handlers are removed when saved.</div>
                    </div>
                </div>
                <div class="wide">
                    <label for="long_html_editor">Long description — full HTML</label>
                    <div class="catalog-rich" data-rich-editor>
                        <div class="catalog-rich-toolbar" role="toolbar" aria-label="Long description formatting">
                            <button type="button" data-command="bold" title="Bold"><strong>B</strong></button>
                            <button type="button" data-command="italic" title="Italic"><em>I</em></button>
                            <button type="button" data-command="insertUnorderedList" title="Bulleted list">• List</button>
                            <button type="button" data-command="insertOrderedList" title="Numbered list">1. List</button>
                            <button type="button" data-source-toggle>HTML source</button>
                        </div>
                        <div id="long_html_editor" role="textbox" aria-multiline="true" contenteditable="true" class="catalog-rich-canvas" style="min-height:270px" data-canvas data-placeholder="Full product content, specs, care and size guides">{!! $longValue !!}</div>
                        <textarea hidden class="catalog-rich-source" data-html-source name="long_description_html">{{ $longValue }}</textarea>
                        <div class="catalog-rich-footer">CJ-provided HTML is sanitized. For advanced HTML, edit the source and Save Changes; reopen to see its cleaned visual form.</div>
                    </div>
                </div>
                <div class="wide">
                    <label for="prod_desc">Internal plain-text notes</label>
                    <textarea id="prod_desc" name="description" maxlength="5000" placeholder="Optional internal content notes">{{ old('description', $product->description) }}</textarea>
                </div>
            </div>
        </section>
        <section id="seo" class="card catalog-editor-section mt">
            <h2>SEO details</h2><p class="micro">Search-engine title, meta description and focus keyword.</p>
            <div class="catalog-field-grid">
                <div><label for="seo_title">SEO title</label><input id="seo_title" name="seo_title" maxlength="255" placeholder="Keyword | Store" value="{{ old('seo_title', $product->seo_title) }}"></div>
                <div><label for="focus_keyword">Focus keyword</label><input id="focus_keyword" name="focus_keyword" maxlength="255" placeholder="primary keyword" value="{{ old('focus_keyword', $product->focus_keyword) }}"></div>
                <div class="wide"><label for="seo_description">SEO meta description</label>
                    <textarea id="seo_description" name="seo_description" maxlength="1000" placeholder="Natural, clear summary for search results">{{ old('seo_description', $product->seo_description) }}</textarea><small>Recommended 140–160 characters; update this before listing.</small></div>
            </div>
        </section>
        <section id="pricing" class="card catalog-editor-section mt">
            <h2>Pricing and costs</h2><p class="micro">The CJ source cost is read-only; selling and optional sale prices are yours to set.</p>
            <div class="catalog-field-grid">
                <div><label for="selling_price">Regular selling price (USD)</label><input id="selling_price" type="number" step="0.01" min="0.01" name="selling_price_usd" value="{{ old('selling_price_usd', $product->selling_price_usd) }}" placeholder="39.99"></div>
                <div><label for="sale_price">Discounted / sale price (USD)</label><input id="sale_price" type="number" step="0.01" min="0.01" name="sale_price_usd" value="{{ old('sale_price_usd', $product->sale_price_usd) }}" placeholder="Optional, must be below regular price"></div>
                <div><label for="cj_cost">CJ source price (USD)</label><input id="cj_cost" readonly value="{{ data_get($product->source_snapshot,'sell_price_usd','Not supplied') }}"></div>
                <div><label for="cj_weight">CJ product weight (g)</label><input id="cj_weight" readonly value="{{ $product->source_weight_g ?: 'Not supplied' }}"></div>
            </div>
            <p class="catalog-note" style="margin-top:14px">Shipping cost and live warehouse inventory are not currently fetched. Verify both before publishing any product.</p>
        </section>
        <section id="images" class="card catalog-editor-section mt">
            <h2>Product images</h2><p class="micro">Main image and optional gallery. All URLs must be HTTPS; images are not downloaded to DataPilot storage in this release.</p>
            <div class="catalog-field-grid">
                <div class="wide"><label for="featured_image_url">Featured image URL</label><input id="featured_image_url" name="featured_image_url" maxlength="500" type="url" value="{{ old('featured_image_url', $product->featured_image_url) }}" placeholder="https://..."></div>
                <div class="wide"><label for="gallery_images_text">Gallery image URLs (one per line)</label>
                    <textarea name="gallery_images_text" id="gallery_images_text" style="min-height:105px;font:13px/1.5 ui-monospace,monospace" placeholder="https://example.com/image-1.jpg">{{ old('gallery_images_text', $gallery->implode("\n")) }}</textarea>
                    <small>Up to 20 HTTPS images. Original CJ source image is preserved separately.</small>
                </div>
            </div>
            @if($gallery->isNotEmpty() || $image)
            <div class="catalog-gallery">
                @if($image)<img src="{{ $image }}" alt="Featured product image" referrerpolicy="no-referrer" loading="lazy">@endif
                @foreach($gallery->take(7) as $url)<img src="{{ $url }}" alt="Product gallery image" loading="lazy" referrerpolicy="no-referrer">@endforeach
            </div>
            @endif
        </section>
        </form>

        <section id="variants" class="card catalog-editor-section">
            <div class="split"><div><h2>CJ product variations</h2><p class="micro">Original variant SKU, attributes, costs and weights. Supplier reference only in this release.</p></div><span class="tag">{{ count($variants) }} variants</span></div>
            @if(count($variants))
                <div class="table-wrap mt"><table class="data-table catalog-var-table"><thead><tr>
                    <th>Image</th><th>Variant SKU</th><th>Color / size / key</th><th>CJ cost (USD)</th><th>Weight (g)</th><th>Dimensions</th>
                </tr></thead><tbody>
                @foreach($variants as $variant)<tr>
                    <td>@if($variant['image'] ?? null)<img class="catalog-var-img" src="{{ $variant['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">@else—@endif</td>
                    <td>{{ $variant['sku'] ?? '—' }}</td><td>{{ $variant['key'] ?? '—' }}</td>
                    <td>{{ $variant['price_usd'] ?? '—' }}</td><td>{{ $variant['weight_g'] ?? '—' }}</td>
                    <td>{{ $variant['length'] ?? '—' }} × {{ $variant['width'] ?? '—' }} × {{ $variant['height'] ?? '—' }}</td>
                </tr>@endforeach
                </tbody></table></div>
            @else
                <div class="empty mt">Full CJ variation data is not loaded yet. Use <strong>Fetch complete CJ details</strong> to retrieve it.</div>
            @endif
        </section>

        <section id="publishing" class="card catalog-editor-section">
            <h2>Botble publishing</h2>
            <p class="micro">Publishing is disabled until the signed, customer-scoped Botble product delivery endpoint is implemented and verified.</p>
            <button type="button" class="btn" disabled>Push to Botble — not available yet</button>
            <div class="mt">
            @forelse($stores as $store)
                <div class="split" style="padding:10px 0;border-top:1px solid #e5e7eb">
                    <div><strong>{{ $store->name }}</strong><div class="micro">{{ $store->base_url }}</div></div>
                    <span class="tag {{ $store->status === 'connected' ? 'good' : 'off' }}">{{ ucfirst($store->status) }}</span>
                </div>
            @empty
                <p class="micro">No Botble stores connected to this workspace.</p>
            @endforelse
            </div>
        </section>
        <div class="catalog-editor-footer">
            <div class="flex"><button class="btn btn-primary" type="submit" form="customer-product-form">Save product</button>
                <a class="btn" href="{{ route('saas.workspace.products', $workspace->public_id) }}">Back to My Products</a></div>
            <small class="micro">Changes are saved as a private draft only.</small>
        </div>
    </div>
    <aside class="catalog-editor-side">
        <div class="card">
            <div class="split"><h2>Product readiness</h2><span class="tag">{{ $ready }}/{{ count($checks) }}</span></div>
            <div style="height:8px;background:#e5e7eb;border-radius:9px;overflow:hidden;margin:8px 0 16px">
                <div style="height:100%;background:#22c55e;width:{{ (int) round($ready / count($checks) * 100) }}%"></div>
            </div>
            @foreach($checks as $label=>$ok)
                <div class="split" style="padding:6px 0;border-bottom:1px solid #eef1f6;font-size:12px">
                    <span>{{ $label }}</span><strong style="color:{{ $ok ? '#15803d' : '#94a3b8' }}">{{ $ok ? '✓' : '—' }}</strong>
                </div>
            @endforeach
        </div>
        <div class="card">
            <h2>CJ supplier reference</h2>
            @if($image)<img class="catalog-source-image" src="{{ $image }}" alt="CJ product image" referrerpolicy="no-referrer">@else<div class="empty">No supplier image loaded</div>@endif
            <div class="catalog-source-rows mt">
                <div><span>CJ product ID</span><strong>{{ $product->cj_product_id }}</strong></div>
                <div><span>Supplier SKU</span><strong>{{ data_get($product->source_snapshot,'source_sku', $product->sku) ?: '—' }}</strong></div>
                <div><span>Supplier category</span><strong>{{ data_get($product->source_snapshot,'category', '—') }}</strong></div>
                <div><span>Supplier price</span><strong>{{ data_get($product->source_snapshot,'sell_price_usd') ?? 'Not supplied' }}</strong></div>
                <div><span>Full CJ details fetched</span><strong>{{ $product->cj_details_fetched_at?->format('Y-m-d H:i') ?? 'Not yet — catalog preview only' }}</strong></div>
            </div>
            <form method="post" action="{{ route('saas.workspace.products.cj.details', ['workspace'=>$workspace->public_id,'product'=>$product->id]) }}" style="margin-top:16px">
                @csrf
                <button class="btn btn-primary" style="width:100%" type="submit">Fetch complete CJ details</button>
            </form>
            <p class="micro mt">Uses the workspace's verified CJ connection. Refreshes supplier data, descriptions, gallery and variants; never overwrites your saved merchant edits. Limited to one refresh per workspace per minute.</p>
        </div>
    </aside>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-rich-editor]').forEach(section => {
        const canvas = section.querySelector('[data-canvas]');
        const source = section.querySelector('[data-html-source]');
        const toggle = section.querySelector('[data-source-toggle]');
        let sourceMode = false;
        section.querySelectorAll('[data-command]').forEach(button => {
            button.addEventListener('click', () => {
                if (sourceMode) return;
                canvas.focus();
                document.execCommand(button.dataset.command, false, null);
            });
        });
        toggle.addEventListener('click', () => {
            if (!sourceMode) {
                source.value = canvas.innerHTML;
                canvas.hidden = true;
                source.hidden = false;
                toggle.textContent = 'Visual editor';
                sourceMode = true;
            } else {
                // Never throw away uncommitted HTML or render it before sanitization.
                if (source.value !== canvas.innerHTML) {
                    section.querySelector('.catalog-rich-footer').textContent =
                        'Save the product to sanitize these HTML changes, then reopen to see the visual version.';
                    return;
                }
                canvas.hidden = false;
                source.hidden = true;
                toggle.textContent = 'HTML source';
                sourceMode = false;
            }
        });
        const form = document.getElementById('customer-product-form');
        form.addEventListener('submit', () => {
            if (!sourceMode) source.value = canvas.innerHTML;
            // textarea[hidden] still submits its value.
        });
    });
});
</script>
@endsection
