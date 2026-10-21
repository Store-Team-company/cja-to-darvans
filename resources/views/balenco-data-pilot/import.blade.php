@extends('balenco-data-pilot.layout')

@section('content')
<style>
    .bdp-import-page {
        max-width: 1180px;
    }

    .bdp-import-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 18px;
    }

    .bdp-import-title {
        margin: 0 0 6px;
        font-size: 26px;
        line-height: 1.2;
        color: #0f172a;
    }

    .bdp-import-subtitle {
        margin: 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.5;
    }

    .bdp-import-tabs-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        box-shadow: 0 14px 34px rgba(15, 23, 42, .06);
        overflow: hidden;
    }

    .bdp-import-tabs-nav {
        display: flex;
        gap: 8px;
        padding: 14px;
        border-bottom: 1px solid #e5e7eb;
        background: #f8fafc;
    }

    .bdp-import-tab {
        border: 1px solid #dbe3ef;
        background: #fff;
        color: #334155;
        border-radius: 999px;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
    }

    .bdp-import-tab.active {
        background: #0f172a;
        border-color: #0f172a;
        color: #fff;
    }

    .bdp-import-tab-panel {
        display: none;
        padding: 22px;
    }

    .bdp-import-tab-panel.active {
        display: block;
    }

    .bdp-import-panel-title {
        margin: 0 0 6px;
        font-size: 20px;
        line-height: 1.25;
        color: #0f172a;
    }

    .bdp-import-panel-text {
        margin: 0 0 18px;
        color: #64748b;
        font-size: 13px;
        line-height: 1.5;
    }

    .bdp-import-note {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        color: #334155;
        font-size: 12px;
        line-height: 1.5;
        padding: 10px 12px;
        margin-bottom: 16px;
    }

    .bdp-import-form {
        max-width: 720px;
    }

    .bdp-import-form-row {
        margin-bottom: 14px;
    }

    .bdp-import-form label {
        display: block;
        margin-bottom: 6px;
        color: #334155;
        font-size: 12px;
        font-weight: 800;
    }

    .bdp-import-form input[type="file"],
    .bdp-import-form select {
        width: 100%;
        min-height: 42px;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #fff;
        padding: 10px;
        color: #0f172a;
    }

    .bdp-import-confirm {
        display: flex;
        align-items: center;
        gap: 9px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #7c2d12;
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 12px;
        font-weight: 800;
        margin: 14px 0;
    }

    .bdp-import-confirm input {
        width: 15px;
        height: 15px;
        margin: 0;
    }

    .bdp-import-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .bdp-import-help {
        color: #64748b;
        font-size: 12px;
        line-height: 1.5;
    }

    .bdp-import-error {
        color: #b91c1c;
        font-size: 12px;
        margin: 6px 0 0;
    }

    .bdp-import-mini-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-top: 16px;
    }

    .bdp-import-mini-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        color: #475569;
        font-size: 12px;
        line-height: 1.45;
    }

    .bdp-import-mini-card strong {
        display: block;
        color: #0f172a;
        margin-bottom: 4px;
    }

    @media (max-width: 900px) {
        .bdp-import-header {
            flex-direction: column;
        }

        .bdp-import-tabs-nav {
            flex-direction: column;
        }

        .bdp-import-tab {
            width: 100%;
            text-align: left;
        }

        .bdp-import-mini-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="bdp-import-page">
    <div class="bdp-import-header">
        <div>
            <h1 class="bdp-import-title">Imports</h1>
            <p class="bdp-import-subtitle">Import new products or update existing product SEO/content safely.</p>
        </div>
        <a class="bdp-btn light btn light" href="{{ route('bdp.products') }}">Back to Products</a>
    </div>

    <div class="bdp-import-tabs-card">
        <div class="bdp-import-tabs-nav" role="tablist">
            <button type="button" class="bdp-import-tab active" data-bdp-import-tab="seo">Update Products from SEO CSV</button>
            <button type="button" class="bdp-import-tab" data-bdp-import-tab="products">Import Products</button>
        </div>

        <div class="bdp-import-tab-panel active" data-bdp-import-panel="seo">
            <h2 class="bdp-import-panel-title">Update Products from SEO CSV</h2>
            <p class="bdp-import-panel-text">Use the optimized Data Pilot SEO CSV to update existing products.</p>

            <div class="bdp-import-note">
                <strong>Match rule:</strong> product_id is used first. SKU can be updated. Do not change row_type or product_id.
            </div>

            <form class="bdp-import-form" method="post" action="{{ route('bdp.bulk.optimization.import') }}" enctype="multipart/form-data">
                @csrf

                <div class="bdp-import-form-row">
                    <label>Optimized SEO CSV</label>
                    <input type="file" name="csv_file" accept=".csv,.txt,text/csv" required>
                    @error('csv_file')<p class="bdp-import-error">{{ $message }}</p>@enderror
                </div>

                <label class="bdp-import-confirm">
                    <input type="checkbox" name="update_products" value="1" required>
                    Yes, update existing products
                </label>
                @error('update_products')<p class="bdp-import-error">{{ $message }}</p>@enderror

                <div class="bdp-import-actions">
                    <button class="bdp-btn orange btn orange" type="submit">Update Products from SEO CSV</button>
                    <span class="bdp-import-help">Review products after import, then sync selected products.</span>
                </div>
            </form>
        </div>

        <div class="bdp-import-tab-panel" data-bdp-import-panel="products">
            <h2 class="bdp-import-panel-title">Import Products</h2>
            <p class="bdp-import-panel-text">Upload WooCommerce CSV or direct CJA Excel/CSV product files.</p>

            <form class="bdp-import-form" method="post" action="{{ route('bdp.import') }}" enctype="multipart/form-data">
                @csrf

                <div class="bdp-import-form-row">
                    <label>Import Source</label>
                    <select name="source" required>
                        <option value="woocommerce">WooCommerce CSV</option>
                        <option value="cja">CJA Excel / CSV</option>
                    </select>
                    @error('source')<p class="bdp-import-error">{{ $message }}</p>@enderror
                </div>

                <div class="bdp-import-form-row">
                    <label>Product File</label>
                    <input type="file" name="file" accept=".csv,.txt,.xlsx,.xlsm" required>
                    @error('file')<p class="bdp-import-error">{{ $message }}</p>@enderror
                </div>

                <div class="bdp-import-actions">
                    <button class="bdp-btn orange btn orange" type="submit">Import Products</button>
                </div>
            </form>

            <div class="bdp-import-mini-grid">
                <div class="bdp-import-mini-card">
                    <strong>CJA import</strong>
                    Reads title, SKU, specification, inventory, cost, and variation images.
                </div>
                <div class="bdp-import-mini-card">
                    <strong>Parent images</strong>
                    Parent gallery is kept manual for better final image quality.
                </div>
                <div class="bdp-import-mini-card">
                    <strong>Variation images</strong>
                    Imported and reused by color where available.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var tabs = document.querySelectorAll('[data-bdp-import-tab]');
    var panels = document.querySelectorAll('[data-bdp-import-panel]');

    function activate(tabName) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('active', tab.getAttribute('data-bdp-import-tab') === tabName);
        });

        panels.forEach(function (panel) {
            panel.classList.toggle('active', panel.getAttribute('data-bdp-import-panel') === tabName);
        });

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url.toString());
        } catch (e) {}
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activate(tab.getAttribute('data-bdp-import-tab'));
        });
    });

    try {
        var current = new URL(window.location.href).searchParams.get('tab');
        if (current === 'products' || current === 'seo') {
            activate(current);
        }
    } catch (e) {}
})();
</script>
@endsection