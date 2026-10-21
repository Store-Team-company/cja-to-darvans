@extends('balenco-data-pilot.layout')

@section('title', 'Category Mapping | Balenco Data Pilot')

@section('content')
<style>
    .bdp-category-shell{display:flex;flex-direction:column;gap:18px}
    .bdp-category-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}
    .bdp-category-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .bdp-category-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:18px;align-items:start}
    .bdp-category-form-grid{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1fr) auto;gap:12px;align-items:end}
    .bdp-category-card{padding:20px}
    .bdp-category-path{font-weight:800;color:#0f172a}
    .bdp-category-muted{color:#64748b;font-size:12px;line-height:1.45}
    .bdp-category-empty{border:1px dashed #cbd5e1;border-radius:18px;background:#f8fafc;padding:30px;text-align:center;color:#475569}
    @media(max-width:1000px){.bdp-category-grid{grid-template-columns:1fr}.bdp-category-form-grid{grid-template-columns:1fr}.bdp-category-head{flex-direction:column}.bdp-category-actions{justify-content:flex-start}}
</style>

<div class="bdp-category-shell">
    <div class="bdp-category-head">
        <div class="bdp-page-title">
            <h1>Category Mapping</h1>
            <p>Connect CJ/Data Pilot categories to real Balenco/Shofy categories before syncing products.</p>
        </div>
        <div class="bdp-category-actions">
            <form method="post" action="{{ route('bdp.categories.mapping.fetch') }}">
                @csrf
                <button class="bdp-btn light" type="submit">Fetch Balenco Categories</button>
            </form>
            <form method="post" action="{{ route('bdp.categories.mapping.auto') }}">
                @csrf
                <button class="bdp-btn orange" type="submit">Auto-map Similar Names</button>
            </form>
        </div>
    </div>

    @if(session('success'))<div class="bdp-alert success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bdp-alert danger">{{ session('error') }}</div>@endif

    <div class="bdp-grid bdp-stats" style="grid-template-columns:repeat(4,minmax(160px,1fr))">
        <div class="bdp-stat-card"><div class="bdp-stat-icon is-blue">⌁</div><div><div class="bdp-stat-label">Source Categories</div><div class="bdp-stat-number">{{ $sourceCategories->count() }}</div><div class="bdp-stat-note">Found in Data Pilot</div></div></div>
        <div class="bdp-stat-card"><div class="bdp-stat-icon is-green">✓</div><div><div class="bdp-stat-label">Mapped</div><div class="bdp-stat-number">{{ $mappings->count() }}</div><div class="bdp-stat-note">Saved mappings</div></div></div>
        <div class="bdp-stat-card"><div class="bdp-stat-icon is-orange">!</div><div><div class="bdp-stat-label">Pending</div><div class="bdp-stat-number">{{ max($sourceCategories->count() - $mappings->count(), 0) }}</div><div class="bdp-stat-note">Need mapping</div></div></div>
        <div class="bdp-stat-card"><div class="bdp-stat-icon is-purple">□</div><div><div class="bdp-stat-label">Balenco Categories</div><div class="bdp-stat-number">{{ $balencoCategories->count() }}</div><div class="bdp-stat-note">Fetched from Shofy</div></div></div>
    </div>

    <div class="bdp-category-grid">
        <div class="bdp-card bdp-category-card">
            <div class="bdp-card-header"><div><h2 class="bdp-card-title">Map Category</h2><p class="bdp-card-subtitle">Choose a Data Pilot/CJ category and connect it to the final Balenco category.</p></div></div>

            @if($balencoCategories->isEmpty())
                <div class="bdp-category-empty"><h3>No Balenco categories fetched yet</h3><p>Click <strong>Fetch Balenco Categories</strong> first. This reads categories from <code>ec_product_categories</code>.</p></div>
            @else
                <form method="post" action="{{ route('bdp.categories.mapping.store') }}" class="bdp-category-form-grid">
                    @csrf
                    <div>
                        <label>Data Pilot / CJ category</label>
                        <select name="source_category" required>
                            <option value="">Select source category...</option>
                            @foreach($sourceCategories as $source)
                                <option value="{{ $source }}">{{ $source }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Balenco category</label>
                        <select name="balenco_category_id" required>
                            <option value="">Select Balenco category...</option>
                            @foreach($balencoCategories as $category)
                                <option value="{{ $category->balenco_category_id }}">{{ $category->path ?: $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="bdp-btn orange" type="submit">Save Mapping</button>
                </form>
            @endif
        </div>

        <div class="bdp-card bdp-category-card">
            <h2 class="bdp-card-title">How this helps</h2>
            <p class="bdp-card-subtitle">Category mapping keeps product sync clean and avoids manual typing mistakes.</p>
            <div style="height:12px"></div>
            <div class="bdp-alert" style="background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a"><strong>Recommended:</strong> Fetch Balenco categories, map broad CJ categories once, then let Data Pilot reuse mappings during product cleanup.</div>
            <div style="height:12px"></div>
            <a href="{{ route('bdp.settings') }}" class="bdp-btn light">Open Settings</a>
        </div>
    </div>

    <div class="bdp-card bdp-category-card">
        <div class="bdp-card-header"><div><h2 class="bdp-card-title">Saved Mappings</h2><p class="bdp-card-subtitle">These mappings can be reused when assigning categories to selected products.</p></div></div>
        <div class="bdp-table-wrap">
            <table class="bdp-table">
                <thead><tr><th>Data Pilot / CJ Category</th><th>Balenco Category</th><th>Balenco ID</th><th>Notes</th></tr></thead>
                <tbody>
                    @forelse($mappings as $mapping)
                        <tr>
                            <td><div class="bdp-category-path">{{ $mapping->source_category }}</div></td>
                            <td><div class="bdp-category-path">{{ $mapping->balenco_category_path ?: $mapping->balenco_category_name }}</div><div class="bdp-category-muted">{{ $mapping->balenco_category_name }}</div></td>
                            <td>{{ $mapping->balenco_category_id ?: '—' }}</td>
                            <td>{{ $mapping->notes ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="bdp-category-empty">No mappings saved yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
