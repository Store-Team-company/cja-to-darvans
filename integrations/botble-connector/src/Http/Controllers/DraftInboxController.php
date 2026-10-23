<?php

namespace Botble\DatapilotConnector\Http\Controllers;

use Botble\DatapilotConnector\Models\ConnectorPairing;
use Botble\DatapilotConnector\Models\IncomingDraft;
use Botble\DatapilotConnector\Services\ConnectorSigning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DraftInboxController extends Controller
{
    private const PATH = '/api/datapilot/v1/drafts';

    public function stage(Request $request): JsonResponse
    {
        // By default this plugin does not accept product writes from the network.
        if (!config('datapilot-connector.inbound_enabled', false)) {
            return response()->json(['message' => 'Inbound product drafts are disabled.'], 503);
        }
        $body = (string) $request->getContent();
        if ($body === '' || strlen($body) > 262144) {
            return response()->json(['message' => 'Invalid product size.'], 413);
        }

        $storeId = (string) $request->header('X-DataPilot-Store');
        $pair = ConnectorPairing::where('store_public_id', $storeId)
            ->where('status', 'paired')->first();

        $valid = $pair && $pair->shared_secret
            && ConnectorSigning::valid(
                $pair->shared_secret, 'POST', self::PATH,
                (string) $request->header('X-DataPilot-Timestamp'),
                (string) $request->header('X-DataPilot-Nonce'),
                $body,
                (string) $request->header('X-DataPilot-Signature')
            );
        if (!$valid) {
            return response()->json(['message' => 'Invalid signed request.'], 401);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || array_is_list($data)) {
            return response()->json(['message' => 'Invalid JSON object.'], 422);
        }
        // Backward-compatible v1 <= 64KiB and v2 <= 256KiB.
        // Never accept an undocumented schema number or a partial version bump.
        $v2 = ($data['schema_version'] ?? null) === 2;
        if (array_key_exists('schema_version', $data) && !$v2) {
            return response()->json(['message' => 'Unsupported draft schema.'], 422);
        }
        if ($v2 && !config('datapilot-connector.variant_drafts_enabled', false)) {
            return response()->json(['message' => 'Complete variant drafts are disabled.'], 503);
        }
        if (!$v2 && strlen($body) > 65536) {
            return response()->json(['message' => 'Invalid v1 product size.'], 413);
        }

        $rules = [
            'idempotency_key' => ['required', 'uuid'],
            'source' => ['required', 'in:cj'],
            'source_product_id' => ['required', 'string', 'max:160'],
            'title' => ['required', 'string', 'min:1', 'max:250'],
            'sku' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'images' => ['sometimes', 'array', 'max:8'],
            'images.*' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'category_ids' => ['sometimes', 'array', 'max:10'],
            'category_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'variants' => ['sometimes', 'array', 'max:'.($v2 ? 100 : 20)],
            'variants.*.source_variant_id' => ['nullable', 'string', 'max:180'],
            'variants.*.sku' => ['required', 'string', 'max:180'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.quantity' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'variants.*.attributes' => ['sometimes', 'array', 'max:5'],
            'variants.*.image' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
        ];
        if ($v2) {
            $rules += [
                'schema_version' => ['required', 'integer', 'in:2'],
                'variant_count' => ['required', 'integer', 'min:1', 'max:100'],
                'short_description_html' => ['present', 'string', 'max:60000'],
                'long_description_html' => ['present', 'string', 'max:60000'],
                'seo_title' => ['present', 'string', 'max:255'],
                'seo_description' => ['present', 'string', 'max:1000'],
                'category_name' => ['present', 'string', 'max:200'],
                'weight_g' => ['sometimes', 'regex:/^\d{1,8}(?:\.\d{1,3})?$/D'],
                'variants.*.weight_g' => ['sometimes', 'regex:/^\d{1,8}(?:\.\d{1,3})?$/D'],
            ];
            $rules['images'] = ['required', 'array', 'max:20'];
            $rules['variants'] = ['required', 'array', 'min:1', 'max:100'];
            $rules['variants.*.source_variant_id'] = ['required', 'string', 'max:160'];
            $rules['variants.*.attributes'] = ['required', 'array', 'min:1', 'max:5'];
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid draft product payload.'], 422);
        }
        // Require exactly the documented contract: silently accepting
        // unexpected business fields would lead to accidental publishing
        // behaviors in future connector versions.
        $extra = array_diff(array_keys($data), [
            'idempotency_key', 'source', 'source_product_id', 'title', 'sku', 'description',
            'price', 'quantity', 'images', 'category_ids', 'variants',
        ]);
        if ($v2) {
            $extra = array_diff($extra, [
                'schema_version', 'variant_count',
                'short_description_html', 'long_description_html',
                'seo_title', 'seo_description', 'category_name', 'weight_g',
            ]);
        }
        if ($extra) {
            return response()->json(['message' => 'Unknown product fields.'], 422);
        }
        foreach ($data['variants'] ?? [] as $variant) {
            if (array_diff(array_keys($variant), $v2
                ? ['sku', 'price', 'quantity', 'attributes', 'source_variant_id', 'image', 'weight_g']
                : ['sku', 'price', 'quantity', 'attributes', 'source_variant_id', 'image'])) {
                return response()->json(['message' => 'Unknown variation fields.'], 422);
            }
            foreach (($variant['attributes'] ?? []) as $key => $value) {
                if (!is_string($key) || trim($key) === '' || strlen($key) > 100
                    || !is_string($value) || trim($value) === '' || strlen($value) > 100) {
                    return response()->json(['message' => 'Invalid variation attributes.'], 422);
                }
            }
        }

        if ($v2) {
            if (count($data['variants']) !== (int) $data['variant_count']) {
                return response()->json(['message' => 'Incomplete variant collection.'], 422);
            }
            $seenIds = [];
            $seenSkus = [];
            $seenOptions = [];
            foreach ($data['variants'] as $variant) {
                $id = (string) $variant['source_variant_id'];
                $sku = (string) $variant['sku'];
                $attrs = $variant['attributes'] ?? [];
                if (isset($seenIds[$id]) || isset($seenSkus[$sku])
                    || $sku === $data['sku'] || !isset($attrs['CJ Option'])
                    || !is_string($attrs['CJ Option']) || trim($attrs['CJ Option']) === '') {
                    return response()->json(['message' => 'Ambiguous supplier variation.'], 422);
                }
                if (count($attrs) !== 1 || isset($seenOptions[$attrs['CJ Option']])) {
                    return response()->json(['message' => 'Duplicated or unmapped variation option.'], 422);
                }
                // This v2 rollout permits only the merchant-confirmed uniform
                // retail price. No implicit cost-based or zero-cost prices.
                if (!is_numeric($variant['price'] ?? null)
                    || (float) $variant['price'] <= 0
                    || (string) $variant['price'] !== (string) $data['price']) {
                    return response()->json(['message' => 'Unreviewed variation pricing.'], 422);
                }
                $seenIds[$id] = true;
                $seenSkus[$sku] = true;
                $seenOptions[$attrs['CJ Option']] = true;
            }
            foreach (['short_description_html', 'long_description_html'] as $field) {
                // Sanitized at the sender, but never rely on a sender alone.
                try {
                    $data[$field] = app(\Botble\DatapilotConnector\Services\SignedDraftHtmlSanitizer::class)
                        ->clean($data[$field]) ?? '';
                } catch (\Throwable) {
                    return response()->json(['message' => 'Invalid HTML descriptions.'], 422);
                }
            }
        }

        $timestamp = (int) $request->header('X-DataPilot-Timestamp');
        $nonce = (string) $request->header('X-DataPilot-Nonce');
        $digest = hash('sha256', $body);
        $result = DB::transaction(function () use ($pair, $nonce, $timestamp, $data, $digest): array {
            // DB-enforced nonce uniqueness prevents concurrent replay, even
            // with separate PHP workers or unshared Laravel cache backends.
            $inserted = DB::table('datapilot_connector_nonces')->insertOrIgnore([
                'pairing_id' => $pair->id,
                'nonce' => $nonce,
                'expires_at' => \Illuminate\Support\Carbon::createFromTimestampUTC($timestamp + 600),
            ]);
            if (!$inserted) {
                return ['status' => 409, 'message' => 'Replayed request.'];
            }

            $existing = IncomingDraft::where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()->first();
            if ($existing) {
                if ($existing->pairing_id !== $pair->id || $existing->payload_sha256 !== $digest) {
                    return ['status' => 409, 'message' => 'Idempotency key conflict.'];
                }
                return ['status' => 200, 'message' => 'Draft already staged.', 'id' => $existing->id];
            }

            $draft = new IncomingDraft();
            $draft->pairing_id = $pair->id;
            $draft->idempotency_key = $data['idempotency_key'];
            $draft->source_product_id = $data['source_product_id'];
            $draft->title = $data['title'];
            $draft->sku = $data['sku'] ?? null;
            $draft->payload_sha256 = $digest;
            $draft->payload = $data;
            $draft->status = 'pending_review';
            $draft->save();

            return ['status' => 202, 'message' => 'Draft staged for review only.', 'id' => $draft->id];
        });

        return response()->json(
            ['message' => $result['message'], 'draft_id' => $result['id'] ?? null],
            $result['status']
        )->header('Cache-Control', 'no-store');
    }
}
