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
        if ($body === '' || strlen($body) > 65536) {
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
        $validator = Validator::make($data, [
            'idempotency_key' => ['required', 'uuid'],
            'source' => ['required', 'in:cj'],
            'source_product_id' => ['required', 'string', 'max:160'],
            'title' => ['required', 'string', 'min:1', 'max:250'],
            'sku' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'variants' => ['sometimes', 'array', 'max:20'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:180'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid draft product payload.'], 422);
        }
        // Require exactly the documented contract: silently accepting
        // unexpected business fields would lead to accidental publishing
        // behaviors in future connector versions.
        $extra = array_diff(array_keys($data), [
            'idempotency_key', 'source', 'source_product_id', 'title', 'sku', 'description', 'variants',
        ]);
        if ($extra) {
            return response()->json(['message' => 'Unknown product fields.'], 422);
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
