<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasBotbleStore;
use App\Models\SaasProduct;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasBotbleDeliveryException;
use App\Services\Saas\SaasBotbleDraftDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SaasBotbleDraftDeliveryController extends Controller
{
    public function __invoke(
        Request $request,
        SaasWorkspace $workspace,
        SaasProduct $product,
        SaasBotbleStore $store,
        SaasBotbleDraftDelivery $delivery
    ): RedirectResponse {
        abort_unless($product->workspace_id === $workspace->id && $store->workspace_id === $workspace->id, 404);
        abort_unless($delivery->allowed($request->user(), $workspace, $product, $store), 403);
        try {
            $result = $delivery->stage($request->user(), $workspace, $product, $store);
        } catch (SaasBotbleDeliveryException $e) {
            $safe = match ($e->reason) {
                'product_not_ready' => 'Fetch full CJ details and set a reviewed selling price, product title and SKU first.',
                'variants_not_supported' => 'Variant-bearing products require verified Botble variation mapping. Start with a simple product.',
                'in_progress' => 'This draft is already being sent. Refresh its delivery status before retrying.',
                'inbox_disabled' => 'Botble inbound drafts are disabled. Enable the inbox on the verified sandbox connector.',
                'signature_rejected' => 'Botble rejected the signing key. Reverify the store pairing.',
                'payload_rejected', 'payload_too_large' => 'Botble rejected the draft fields. The product was not confirmed as received.',
                'connection_changed' => 'Store pairing changed during delivery. Reverify your store connection.',
                default => 'Delivery could not be confirmed. Retrying will reuse the same idempotency key.',
            };
            return back()->withErrors(['botble_delivery' => $safe]);
        }

        return back()->with('status', $result['status'] === 'already_staged'
            ? 'This exact product revision is already staged for Botble review. No duplicate was sent.'
            : 'Signed delivery accepted by Botble. The product is waiting in Incoming Products; it is NOT published or listed in the store.');
    }
}
