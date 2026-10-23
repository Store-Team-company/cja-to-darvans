<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasProduct;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasCjPilot;
use App\Services\Saas\SaasCjApiException;
use App\Services\Saas\SaasCjProductDetailsFetcher;
use App\Services\Saas\SaasProductHtmlSanitizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SaasProductEditorController extends Controller
{
    private function authorizeEdit(Request $request, SaasWorkspace $workspace, SaasProduct $product): void
    {
        // Never infer product tenancy from the ID or a submitted workspace field.
        abort_unless($product->workspace_id === $workspace->id, 404);
        abort_unless($workspace->status === 'active' && $product->status === 'draft', 403);
        abort_unless(app(WorkspacePermissions::class)->allows($request->user(), $workspace, 'edit_draft'), 403);

        $entitled = app(SaasEntitlements::class)->active($workspace);
        $pilot = app(SaasCjPilot::class)->allows($workspace)
            && app(WorkspacePermissions::class)->roleFor($request->user(), $workspace) === 'owner';
        abort_unless($entitled || $pilot, 403);
    }

    public function edit(Request $request, SaasWorkspace $workspace, SaasProduct $product): View
    {
        $this->authorizeEdit($request, $workspace, $product);

        return view('saas.product-edit', [
            'workspace' => $workspace,
            'product' => $product,
            // Only authorized workspace stores are loaded. The server-side delivery
            // guard requires public_id, workspace_id, and signing-key presence.
            // shared_secret is encrypted at rest, hidden from JSON and never
            // rendered in Blade; omitting it silently hid the staging action.
            'stores' => $workspace->stores()->orderBy('name')->get([
                'id', 'name', 'base_url', 'status', 'public_id', 'workspace_id', 'shared_secret',
            ]),
            'role' => app(WorkspacePermissions::class)->roleFor($request->user(), $workspace),
            'activeSubscription' => app(SaasEntitlements::class)->active($workspace),
            'accessSource' => app(SaasEntitlements::class)->accessSource($workspace),
        ]);
    }

    public function update(Request $request, SaasWorkspace $workspace, SaasProduct $product): RedirectResponse
    {
        $this->authorizeEdit($request, $workspace, $product);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:250'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:180'],
            'category_name' => ['nullable', 'string', 'max:200'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description_html' => ['nullable', 'string', 'max:60000'],
            'long_description_html' => ['nullable', 'string', 'max:60000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'selling_price_usd' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'sale_price_usd' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'featured_image_url' => ['nullable', 'url', 'max:500', 'starts_with:https://'],
            'gallery_images_text' => ['nullable', 'string', 'max:11000'],
        ]);
        $price = $data['selling_price_usd'] ?? null;
        $sale = $data['sale_price_usd'] ?? null;
        if ($sale !== null && ($price === null || (float) $sale >= (float) $price)) {
            throw ValidationException::withMessages(['sale_price_usd' => 'The sale price must be less than the selling price.']);
        }
        $safe = app(SaasProductHtmlSanitizer::class);
        $gallery = preg_split('/[\r\n,]+/', trim((string) ($data['gallery_images_text'] ?? ''))) ?: [];
        $gallery = array_values(array_unique(array_filter(array_map('trim', $gallery))));
        if (count($gallery) > 20 || collect($gallery)->contains(fn ($url) => !$safe->safeUrl($url))) {
            throw ValidationException::withMessages(['gallery_images_text' => 'Provide up to 20 valid HTTPS image URLs.']);
        }
        try {
            $shortHtml = $safe->clean($data['short_description_html'] ?? null);
            $longHtml = $safe->clean($data['long_description_html'] ?? null);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['long_description_html' => 'The HTML content could not be cleaned safely.']);
        }
        $slugInput = trim((string) ($data['slug'] ?? ''));
        $slug = Str::slug($slugInput ?: $data['title']);
        if ($slug === '' || strlen($slug) > 255) {
            throw ValidationException::withMessages(['slug' => 'Use a valid product URL slug.']);
        }

        DB::transaction(function () use ($request, $workspace, $product, $data, $slug, $shortHtml, $longHtml, $gallery): void {
            $lockedWorkspace = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $locked = $lockedWorkspace->products()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->authorizeEdit($request, $lockedWorkspace, $locked);
            $locked->fill([
                'title' => trim($data['title']),
                'slug' => $slug,
                'sku' => trim((string) ($data['sku'] ?? '')) ?: null,
                'category_name' => trim((string) ($data['category_name'] ?? '')) ?: null,
                'tags' => trim((string) ($data['tags'] ?? '')) ?: null,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'short_description_html' => $shortHtml,
                'long_description_html' => $longHtml,
                'seo_title' => trim((string) ($data['seo_title'] ?? '')) ?: null,
                'seo_description' => trim((string) ($data['seo_description'] ?? '')) ?: null,
                'focus_keyword' => trim((string) ($data['focus_keyword'] ?? '')) ?: null,
                'selling_price_usd' => $data['selling_price_usd'] ?? null,
                'sale_price_usd' => $data['sale_price_usd'] ?? null,
                'featured_image_url' => $data['featured_image_url'] ?? null,
                'gallery_images' => $gallery,
                'merchant_edited_at' => now(),
            ]);
            $locked->save();
        });

        return redirect()->route('saas.workspace.products.edit', [
            'workspace' => $workspace->public_id, 'product' => $product->id,
        ])->with('status', 'Product details saved as a private draft. Nothing was sent to Botble.');
    }

    public function fetchCjDetails(
        Request $request, SaasWorkspace $workspace, SaasProduct $product,
        SaasCjProductDetailsFetcher $fetcher
    ): RedirectResponse {
        $this->authorizeEdit($request, $workspace, $product);
        try {
            $fetcher->fetch($request->user(), $workspace, $product);
        } catch (SaasCjApiException $e) {
            $message = match ($e->reason) {
                'product_cooldown' => 'Full CJ details can be fetched once per minute per workspace.',
                'connection_changed' => 'CJ connection changed or expired. Reverify it in Connections.',
                'rate_limited' => 'CJ is limiting requests. Try again later.',
                'token_expired' => 'CJ rejected the access token. Reverify the connection.',
                default => 'Unable to load full CJ details safely. No product fields were changed.',
            };
            return back()->withErrors(['cj_details' => $message]);
        }

        return back()->with('status', 'Full CJ details fetched: HTML description, images, and variants were saved to this private draft.');
    }
}
