@extends('balenco-data-pilot.layout')

@section('content')
<div class="card">
    <h2>Bulk SEO / Content Edit</h2>
    <p>Apply SEO/content templates to selected products. Leave any field blank or set action to <strong>Ignore</strong> if you do not want to change it.</p>
    <p style="background:#eff6ff;border:1px solid #bfdbfe;padding:10px;border-radius:10px"><strong>Available tokens:</strong> <code>{product_name}</code>, <code>{sku}</code>, <code>{category}</code>, <code>{brand}</code>, <code>{store}</code>, <code>{price}</code></p>
</div>

<form method="post" action="{{ route('bdp.bulk.update') }}">
    @csrf
    @foreach($ids as $id)
        <input type="hidden" name="products[]" value="{{ $id }}">
    @endforeach

    <div class="card">
        <h3>Selected Products</h3>
        <table>
            <thead><tr><th>Product</th><th>SKU</th><th>Variations</th><th>Current Category</th></tr></thead>
            <tbody>
            @foreach($products as $product)
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->sku }}</td>
                    <td>{{ $product->variations_count }}</td>
                    <td>{{ $product->categories }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Category & Tags</h3>
        <div class="grid">
            <div>
                <label><strong>Category action</strong></label>
                <select name="category_action">
                    <option value="ignore">Ignore</option>
                    <option value="replace">Replace categories</option>
                    <option value="append">Append categories</option>
                </select>
            </div>
            <div>
                <label><strong>Categories</strong></label>
                <input name="categories" placeholder="Heels & Pumps">
            </div>
            <div>
                <label><strong>Tags action</strong></label>
                <select name="tags_action">
                    <option value="ignore">Ignore</option>
                    <option value="replace">Replace tags</option>
                    <option value="append">Append tags</option>
                </select>
            </div>
            <div>
                <label><strong>Tags</strong></label>
                <input name="tags" placeholder="women shoes, heels, pumps, slingback">
            </div>
        </div>
    </div>

    <div class="card">
        <h3>SEO Templates</h3>
        <div class="grid">
            <div>
                <label><strong>Meta title template</strong></label>
                <input name="seo_title_template" maxlength="255" placeholder="{product_name} | Balenco">
                <small>Recommended: under 60 characters when possible.</small>
            </div>
            <div>
                <label><strong>Focus keyword action</strong></label>
                <select name="focus_keyword_action">
                    <option value="ignore">Ignore</option>
                    <option value="replace">Replace focus keyword</option>
                </select>
                <input name="focus_keyword" placeholder="women kitten heel pumps" style="margin-top:8px">
            </div>
        </div>
        <div style="margin-top:16px">
            <label><strong>Meta description template</strong></label>
            <textarea name="seo_description_template" placeholder="Shop {product_name} at Balenco. Stylish women’s shoes in trendy colors and easy everyday looks."></textarea>
            <small>Recommended: around 140–160 characters when possible.</small>
        </div>
    </div>

    <div class="card">
        <h3>Content Templates</h3>
        <div>
            <label><strong>Short description template</strong></label>
            <textarea name="short_description_template" placeholder="Step into polished everyday style with {product_name}, designed for versatile outfits and modern women’s fashion."></textarea>
        </div>
        <div class="grid" style="margin-top:16px">
            <div>
                <label><strong>Add content prefix</strong></label>
                <textarea name="content_prefix_template" placeholder="<p>Discover {product_name} from Balenco, designed for stylish everyday wear.</p>"></textarea>
                <small>This is added before existing long description.</small>
            </div>
            <div>
                <label><strong>Add content suffix</strong></label>
                <textarea name="content_suffix_template" placeholder="<p>Pair these shoes with dresses, trousers, or smart casual outfits for a confident look.</p>"></textarea>
                <small>This is added after existing long description.</small>
            </div>
        </div>
    </div>

    <div class="card" style="display:flex;gap:10px;align-items:center">
        <button class="btn orange" type="submit">Apply Bulk Update</button>
        <a class="btn light" href="{{ route('bdp.index') }}">Cancel</a>
    </div>
</form>
@endsection
