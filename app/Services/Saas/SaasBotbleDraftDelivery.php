<?php

namespace App\Services\Saas;

use App\Models\SaasBotbleProductDelivery;
use App\Models\SaasBotbleStore;
use App\Models\SaasProduct;
use App\Models\SaasWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class SaasBotbleDraftDelivery
{
    private const PATH = '/api/datapilot/v1/drafts';

    public function allowed(User $actor, SaasWorkspace $workspace, SaasProduct $product, SaasBotbleStore $store): bool
    {
        if (!config('saas.botble_draft_delivery.enabled', false) || !config('saas.enabled')
            || $workspace->status !== 'active' || $product->workspace_id !== $workspace->id
            || $store->workspace_id !== $workspace->id || $product->status !== 'draft'
            || $store->status !== 'connected' || blank($store->shared_secret)
            || !app(WorkspacePermissions::class)->allows($actor, $workspace, 'publish')
            || !app(SaasEntitlements::class)->allows($workspace, 'publish')) {
            return false;
        }

        // The release is deliberately restricted to one explicitly pinned
        // first-party sandbox, not arbitrary customer-controlled egress URLs.
        $pilot = config('saas.botble_pilot', []);
        return filled($pilot['workspace_id'] ?? null)
            && filled($pilot['store_id'] ?? null)
            && hash_equals((string) $pilot['workspace_id'], (string) $workspace->public_id)
            && hash_equals((string) $pilot['store_id'], (string) $store->public_id)
            && hash_equals('https://sandbox.daystart.pk', (string) $store->base_url)
            && hash_equals('https://sandbox.daystart.pk', (string) ($pilot['store_url'] ?? ''));
    }

    /** @return array{status:string,draft_id:?int} */
    public function stage(User $actor, SaasWorkspace $workspace, SaasProduct $product, SaasBotbleStore $store): array
    {
        if ($product->workspace_id !== $workspace->id || $store->workspace_id !== $workspace->id) {
            abort(404);
        }
        abort_unless($this->allowed($actor, $workspace, $product, $store), 403);

        $reservation = DB::transaction(function () use ($actor, $workspace, $product, $store): array {
            $lockedWorkspace = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $lockedProduct = $lockedWorkspace->products()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedStore = $lockedWorkspace->stores()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allowed($actor, $lockedWorkspace, $lockedProduct, $lockedStore), 403);
            $draftData = $this->draftData($lockedProduct);
            $sourceHash = hash('sha256', json_encode($draftData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $delivery = SaasBotbleProductDelivery::query()
                ->where('product_id', $lockedProduct->id)
                ->where('store_id', $lockedStore->id)->lockForUpdate()->first();

            if ($delivery && $delivery->source_hash === $sourceHash && $delivery->status === 'delivered') {
                return ['already' => true, 'draft_id' => $delivery->remote_draft_id];
            }
            if ($delivery && $delivery->status === 'sending' && $delivery->lease_until?->isFuture()) {
                throw new SaasBotbleDeliveryException('in_progress');
            }

            if (!$delivery) {
                $delivery = new SaasBotbleProductDelivery();
                $delivery->workspace_id = $lockedWorkspace->id;
                $delivery->product_id = $lockedProduct->id;
                $delivery->store_id = $lockedStore->id;
            }
            if ($delivery->source_hash !== $sourceHash || blank($delivery->idempotency_key)) {
                $delivery->idempotency_key = (string) Str::uuid();
                $delivery->source_hash = $sourceHash;
                $delivery->remote_draft_id = null;
            }
            // On unknown network outcomes, replay the identical body and
            // idempotency key. The connector returns 200 for same-body replays.
            $draftData = ['idempotency_key' => $delivery->idempotency_key] + $draftData;
            $body = json_encode($draftData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($body) > 65536) {
                throw new SaasBotbleDeliveryException('payload_too_large');
            }
            $delivery->payload_json = $body;
            $delivery->status = 'sending';
            $delivery->lease_until = now()->addSeconds(45);
            $delivery->attempts = ((int) $delivery->attempts) + 1;
            $delivery->last_error_code = null;
            $delivery->save();

            return [
                'id' => $delivery->id,
                'idempotency_key' => $delivery->idempotency_key,
                'body' => $body,
                'store_public_id' => $lockedStore->public_id,
                'secret' => $lockedStore->shared_secret,
            ];
        });

        if (isset($reservation['already'])) {
            return ['status' => 'already_staged', 'draft_id' => $reservation['draft_id']];
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $mac = hash_hmac('sha256',
            "POST\n".self::PATH."\n".$timestamp."\n".$nonce."\n".$reservation['body'],
            $reservation['secret']
        );

        $status = 'uncertain';
        $reason = 'remote_unavailable';
        $remoteDraftId = null;
        try {
            // No redirect following, no user-controlled URL or callback.
            // The fixed first-party sandbox is the ONLY permitted receiver.
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-DataPilot-Store' => $reservation['store_public_id'],
                    'X-DataPilot-Timestamp' => $timestamp,
                    'X-DataPilot-Nonce' => $nonce,
                    'X-DataPilot-Signature' => 'sha256='.$mac,
                ])->withOptions(['allow_redirects' => false, 'verify' => true, 'proxy' => ''])
                ->connectTimeout(5)->timeout(15)
                ->withBody($reservation['body'], 'application/json')
                ->post('https://sandbox.daystart.pk'.self::PATH);
            $reply = $response->json();
            if (in_array($response->status(), [200, 202], true)
                && is_array($reply) && is_numeric($reply['draft_id'] ?? null)
                && (int) $reply['draft_id'] > 0) {
                $remoteDraftId = (int) $reply['draft_id'];
                $status = 'delivered';
                $reason = null;
            } elseif ($response->status() === 503) {
                $status = 'failed';
                $reason = 'inbox_disabled';
            } elseif (in_array($response->status(), [401, 403], true)) {
                $status = 'failed';
                $reason = 'signature_rejected';
            } elseif ($response->status() === 422) {
                $status = 'failed';
                $reason = 'payload_rejected';
            }
        } catch (Throwable) {
            // Could have reached Botble: preserve idempotency key for retry.
        }

        DB::transaction(function () use ($actor, $workspace, $store, $reservation, $status, $reason, $remoteDraftId): void {
            $lockedWorkspace = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $lockedStore = $lockedWorkspace->stores()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $delivery = SaasBotbleProductDelivery::query()->whereKey($reservation['id'])->lockForUpdate()->firstOrFail();
            if (!hash_equals((string) $delivery->idempotency_key, $reservation['idempotency_key'])) {
                return; // Superseded by an explicit update; never acknowledge the wrong revision.
            }
            if (!$this->allowed($actor, $lockedWorkspace, $lockedWorkspace->products()->findOrFail($delivery->product_id), $lockedStore)
                || !hash_equals((string) $lockedStore->shared_secret, $reservation['secret'])) {
                $delivery->status = 'uncertain';
                $delivery->last_error_code = 'connection_changed';
                $delivery->lease_until = null;
                $delivery->save();
                return;
            }
            $delivery->status = $status;
            $delivery->last_error_code = $reason;
            $delivery->remote_draft_id = $remoteDraftId;
            $delivery->delivered_at = $status === 'delivered' ? now() : null;
            $delivery->lease_until = null;
            $delivery->save();
        });

        $record = SaasBotbleProductDelivery::query()->findOrFail($reservation['id']);
        if ($record->status !== 'delivered') {
            throw new SaasBotbleDeliveryException($record->last_error_code ?? 'remote_unavailable');
        }
        return ['status' => 'staged_for_review', 'draft_id' => $record->remote_draft_id];
    }

    private function draftData(SaasProduct $product): array
    {
        if (!filled($product->cj_product_id) || !filled($product->sku)
            || !filled($product->title) || !$product->cj_details_fetched_at
            || !is_numeric($product->selling_price_usd)
            || (float) $product->selling_price_usd <= 0) {
            throw new SaasBotbleDeliveryException('product_not_ready');
        }
        // Supplier variants need a confirmed Botble attribute mapping.
        // Never silently omit them and create a misleading simple product.
        if (count($product->source_variants ?? []) > 0) {
            throw new SaasBotbleDeliveryException('variants_not_supported');
        }
        $safe = app(SaasProductHtmlSanitizer::class);
        $images = array_values(array_unique(array_filter(
            array_merge([$product->featured_image_url ?: data_get($product->source_snapshot, 'image_url')],
                $product->gallery_images ?? []),
            fn ($url) => $safe->safeUrl($url)
        )));
        $description = trim(strip_tags((string) ($product->long_description_html ?: $product->description ?: '')));
        return [
            'source' => 'cj',
            'source_product_id' => (string) $product->cj_product_id,
            'title' => (string) $product->title,
            'sku' => (string) $product->sku,
            'description' => mb_substr($description, 0, 10000),
            'price' => (string) ($product->sale_price_usd ?: $product->selling_price_usd),
            'images' => array_slice($images, 0, 8),
        ];
    }
}
