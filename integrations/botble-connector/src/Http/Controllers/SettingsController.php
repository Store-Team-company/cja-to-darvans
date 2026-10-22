<?php

namespace Botble\DatapilotConnector\Http\Controllers;

use Botble\DatapilotConnector\Models\ConnectorPairing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('plugins/datapilot-connector::settings', [
            'pairing' => ConnectorPairing::query()->first(),
            'inboxCount' => \Illuminate\Support\Facades\DB::table('datapilot_connector_drafts')->count(),
            'saasUrl' => (string) config('datapilot-connector.saas_url', ''),
        ]);
    }

    public function pair(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'pairing_code' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        if (ConnectorPairing::query()->where('status', 'paired')->exists()) {
            return back()->withErrors(['pairing' => 'Revoke the existing pairing before connecting to a different workspace.']);
        }
        $url = trim((string) config('datapilot-connector.saas_url', ''), '/');
        $site = rtrim(strtolower((string) config('app.url')), '/');
        if (!str_starts_with($url, 'https://') || !filter_var($url, FILTER_VALIDATE_URL)
            || !str_starts_with($site, 'https://') || !filter_var($site, FILTER_VALIDATE_URL)) {
            return back()->withErrors(['pairing' => 'Configure DATAPILOT_SAAS_URL and your public APP_URL as HTTPS in Botble .env.']);
        }

        try {
            $response = Http::acceptJson()->timeout(12)->withOptions(['allow_redirects' => false])
                ->post($url.'/api/saas/botble/pair/complete', [
                    'workspace_id' => $data['workspace_id'],
                    'store_id' => $data['store_id'],
                    'site_url' => $site,
                    'pairing_code' => $data['pairing_code'],
                ]);
            $payload = $response->json();
        } catch (Throwable) {
            return back()->withErrors(['pairing' => 'Unable to reach Data Pilot securely. Check the configured SaaS URL and network.']);
        }

        if (!$response->successful() || !is_array($payload)
            || ($payload['workspace_id'] ?? null) !== $data['workspace_id']
            || ($payload['store_id'] ?? null) !== $data['store_id']
            || ($payload['protocol'] ?? null) !== 'datapilot-v1'
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($payload['shared_secret'] ?? ''))) {
            return back()->withErrors(['pairing' => 'Pairing was rejected. Check that the code is unused, the registered domain matches this Botble site and the subscription is active.']);
        }

        $pair = new ConnectorPairing();
        $pair->workspace_public_id = $data['workspace_id'];
        $pair->store_public_id = $data['store_id'];
        $pair->shared_secret = $payload['shared_secret'];
        $pair->status = 'paired';
        $pair->paired_at = now();
        $pair->save();

        return back()->with('status', 'Botble site successfully paired. Inbound draft transfers remain disabled until integration QA.');
    }

    public function revoke(): RedirectResponse
    {
        $pairing = ConnectorPairing::query()->first();
        if ($pairing) {
            $pairing->status = 'revoked';
            // Remove secrets rather than retain decryption capability.
            $pairing->delete();
        }

        return back()->with('status', 'Local connector pairing revoked. Revoke the matching store in Data Pilot as well.');
    }
}
