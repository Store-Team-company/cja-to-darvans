<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasCjApiException;
use App\Services\Saas\SaasCjCatalogBrowser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SaasCjProductController extends Controller
{
    public function search(Request $request, SaasWorkspace $workspace, SaasCjCatalogBrowser $catalog): RedirectResponse
    {
        abort_unless($catalog->canSearch($request->user(), $workspace), 403);
        $data = Validator::make($request->all(), [
            'mode' => ['required', Rule::in(['search', 'direct'])],
            'keyword' => ['required_if:mode,search', 'nullable', 'string', 'min:2', 'max:100'],
            'page' => ['required_if:mode,search', 'nullable', 'integer', 'min:1', 'max:20'],
            'size' => ['required_if:mode,search', 'nullable', Rule::in(['10', '20', '50', 10, 20, 50])],
            'country_code' => ['nullable', Rule::in(['US', 'CN', 'GB', 'DE'])],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'gte:min_price'],
            'order_by' => ['nullable', Rule::in(['0', '1', '2', '3', '4', 0, 1, 2, 3, 4])],
            'sort' => ['nullable', Rule::in(['asc', 'desc'])],
            'id_type' => ['required_if:mode,direct', 'nullable', Rule::in(['pid', 'productSku', 'variantSku'])],
            'identifier' => ['required_if:mode,direct', 'nullable', 'string', 'min:3', 'max:160', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'view' => ['nullable', Rule::in(['grid', 'list'])],
        ])->validate();

        try {
            $result = $catalog->search($request->user(), $workspace, $data);
        } catch (SaasCjApiException $e) {
            return back()->withErrors(['cj_product' => $this->message($e->reason)])
                ->withInput($request->except(['_token']));
        }

        return redirect()->route('saas.workspace.products', [
            'workspace' => $workspace->public_id,
            'cj_preview' => $result['key'],
            'view' => $data['view'] ?? 'grid',
        ])->with('status', 'CJ search complete. Choose products to import; no drafts were created by searching.');
    }

    public function import(Request $request, SaasWorkspace $workspace, SaasCjCatalogBrowser $catalog): RedirectResponse
    {
        abort_unless($catalog->canSearch($request->user(), $workspace), 403);
        $data = $request->validate([
            'cj_preview' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/D'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'string', 'max:160'],
            'view' => ['nullable', Rule::in(['grid', 'list'])],
        ]);
        $next = route('saas.workspace.products', [
            'workspace' => $workspace->public_id,
            'cj_preview' => $data['cj_preview'],
            'view' => $data['view'] ?? 'grid',
        ]);

        try {
            $stats = $catalog->importSelected(
                $request->user(), $workspace, $data['cj_preview'], $data['items']
            );
        } catch (SaasCjApiException $e) {
            return redirect($next)->withErrors(['cj_product' => $this->message($e->reason)]);
        }

        return redirect($next)->with('cj_product_import', sprintf(
            'Selected CJ products: %d new drafts, %d updated drafts, %d skipped. Nothing sent to Botble.',
            $stats['created'], $stats['updated'], $stats['skipped']
        ));
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            'product_cooldown' => 'CJ search is limited to one request per minute per workspace. Please retry shortly.',
            'token_expired' => 'Your CJ access token was rejected. Test your CJ connection first.',
            'connection_changed' => 'The CJ connection changed or expired. Verify it before continuing.',
            'rate_limited' => 'CJ is limiting API searches. Try again later.',
            'invalid_response' => 'CJ returned an unexpected catalog response. No products were saved.',
            'preview_expired' => 'These CJ search results expired. Please search again.',
            'invalid_selection' => 'The selected products do not match the saved search results.',
            default => 'CJ could not complete the request. No products were saved.',
        };
    }
}
