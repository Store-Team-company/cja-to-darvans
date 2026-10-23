<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasProduct;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasCjPilot;
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
            'stores' => $workspace->stores()->orderBy('name')->get(['id', 'name', 'base_url', 'status']),
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
            'sku' => ['nullable', 'string', 'max:180'],
            'category_name' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'selling_price_usd' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
        ]);

        DB::transaction(function () use ($request, $workspace, $product, $data): void {
            $lockedWorkspace = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $locked = $lockedWorkspace->products()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->authorizeEdit($request, $lockedWorkspace, $locked);
            $locked->fill([
                'title' => trim($data['title']),
                'sku' => trim((string) ($data['sku'] ?? '')) ?: null,
                'category_name' => trim((string) ($data['category_name'] ?? '')) ?: null,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'selling_price_usd' => $data['selling_price_usd'] ?? null,
                'merchant_edited_at' => now(),
            ]);
            // Intentionally do not modify CJ product ID, supplier snapshot,
            // store association or publishing state in a merchant edit.
            $locked->save();
        });

        return redirect()->route('saas.workspace.products.edit', [
            'workspace' => $workspace->public_id,
            'product' => $product->id,
        ])->with('status', 'Draft saved. No product was published or sent to Botble.');
    }
}
