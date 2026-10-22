<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasBotbleStore;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaasBotblePairingController extends Controller
{
    public function issue(Request $request, SaasWorkspace $workspace, SaasBotbleStore $store): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        abort_unless($store->workspace_id === $workspace->id, 404);
        $paid = app(SaasEntitlements::class)->active($workspace);
        $pilot = app(\App\Services\Saas\SaasFirstPartyPilot::class)->allowsPairing($workspace, $store);
        abort_unless($paid || $pilot, 403, 'An active plan or authorized internal pilot is required to pair a store.');
        abort_unless(in_array($store->status, ['pending_verification', 'disconnected', 'revoked'], true), 409,
            'Connected stores must be revoked before pairing again.');

        $code = bin2hex(random_bytes(32));
        DB::transaction(function () use ($store, $code): void {
            $locked = SaasBotbleStore::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'connected', 409);
            DB::table('saas_botble_pairing_challenges')->updateOrInsert(
                ['store_id' => $locked->id],
                ['token_hash' => hash('sha256', $code), 'attempts' => 0,
                    'expires_at' => now()->addMinutes(10), 'used_at' => null,
                    'updated_at' => now(), 'created_at' => now()]
            );
        });

        if ($pilot && !$paid) {
            \Illuminate\Support\Facades\Log::notice('Data Pilot internal Botble pilot pairing code issued', [
                'workspace_id' => $workspace->public_id, 'store_id' => $store->public_id,
            ]);
        }

        // This one-time secret is shown to the authenticated merchant ONCE.
        return back()->with('pairing_code', $code)->with('pairing_store', $store->public_id)
            ->with('status', 'Enter this one-time code in the Data Pilot plugin on your Botble site within ten minutes.');
    }

    public function complete(Request $request): JsonResponse
    {
        if (!config('saas.enabled')
            || (!config('saas.billing_enabled') && !config('saas.botble_pilot.enabled'))) {
            return response()->json(['message' => 'Pairing is unavailable.'], 503);
        }
        $data = $request->validate([
            'workspace_id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'site_url' => ['required', 'url', 'max:255'],
            'pairing_code' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ]);

        $reply = DB::transaction(function () use ($data): ?array {
            $store = SaasBotbleStore::query()->where('public_id', $data['store_id'])
                ->whereHas('workspace', fn ($q) => $q->where('public_id', $data['workspace_id']))
                ->lockForUpdate()->first();

            if (!$store || !in_array($store->status, ['pending_verification', 'disconnected', 'revoked'], true)
                || !(app(SaasEntitlements::class)->active($store->workspace)
                    || app(\App\Services\Saas\SaasFirstPartyPilot::class)->allowsPairing($store->workspace, $store))) {
                return null;
            }

            // Exact match with owner-registered, verified HTTPS host. No
            // dynamically selected SaaS-to-customer callback or SSRF.
            $siteUrl = rtrim(strtolower($data['site_url']), '/');
            if (!hash_equals($store->base_url, $siteUrl)) {
                return null;
            }
            $challenge = DB::table('saas_botble_pairing_challenges')
                ->where('store_id', $store->id)->lockForUpdate()->first();
            if (!$challenge || $challenge->used_at || now()->greaterThanOrEqualTo($challenge->expires_at)
                || $challenge->attempts >= 5) {
                return null;
            }
            DB::table('saas_botble_pairing_challenges')->where('store_id', $store->id)
                ->increment('attempts');
            if (!hash_equals($challenge->token_hash, hash('sha256', $data['pairing_code']))) {
                return null;
            }
            $secret = bin2hex(random_bytes(32));
            $store->shared_secret = $secret; // encrypted Eloquent cast
            $store->status = 'connected';
            $store->last_verified_at = now();
            $store->save();
            if (app(\App\Services\Saas\SaasFirstPartyPilot::class)->allowsPairing($store->workspace, $store)
                && !app(SaasEntitlements::class)->active($store->workspace)) {
                \Illuminate\Support\Facades\Log::notice('Data Pilot internal Botble pilot securely paired', [
                    'workspace_id' => $store->workspace->public_id, 'store_id' => $store->public_id,
                ]);
            }
            DB::table('saas_botble_pairing_challenges')->where('store_id', $store->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);

            return [
                'workspace_id' => $store->workspace->public_id,
                'store_id' => $store->public_id,
                'shared_secret' => $secret,
                'status' => 'connected',
                'protocol' => 'datapilot-v1',
            ];
        });

        return $reply
            ? response()->json($reply, 200)->header('Cache-Control', 'no-store')
            : response()->json(['message' => 'Pairing failed or expired.'], 422)
                ->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, SaasWorkspace $workspace, SaasBotbleStore $store): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        abort_unless($store->workspace_id === $workspace->id, 404);

        DB::transaction(function () use ($store): void {
            $locked = SaasBotbleStore::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $locked->shared_secret = null;
            $locked->status = 'revoked';
            $locked->last_verified_at = null;
            $locked->save();
            DB::table('saas_botble_pairing_challenges')->where('store_id', $locked->id)->delete();
        });

        return back()->with('status', 'Data Pilot has revoked this store. Also revoke the connection in the Botble plugin to stop any previously signed requests.');
    }
}
