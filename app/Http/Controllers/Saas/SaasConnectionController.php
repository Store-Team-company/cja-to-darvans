<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasBotbleStore;
use App\Models\SaasWorkspace;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaasConnectionController extends Controller
{
    public function saveCj(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:16', 'max:512'],
        ]);

        $connection = $workspace->cjConnections()->firstOrNew([]);
        // Reset prior tokens when credentials are replaced.
        $connection->api_key = trim($data['api_key']);
        $connection->access_token = null;
        $connection->refresh_token = null;
        $connection->status = 'pending_verification';
        $connection->last_verified_at = null;
        $connection->save();

        return back()->with('status', 'CJ API key stored securely. API verification is not enabled yet.');
    }

    public function removeCj(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        $connection = $workspace->cjConnections()->first();
        if ($connection) {
            $connection->api_key = null;
            $connection->access_token = null;
            $connection->refresh_token = null;
            $connection->status = 'disconnected';
            $connection->last_verified_at = null;
            $connection->save();
        }

        return back()->with('status', 'CJ credentials removed from this workspace.');
    }

    public function addStore(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'base_url' => ['required', 'url', 'max:255'],
        ]);

        // A store is never contacted or trusted before connector pairing.
        $url = $this->validatedStoreUrl($data['base_url']);
        DB::transaction(function () use ($workspace, $data, $url): void {
            // Serialize competing registrations in the same workspace so a
            // plan's store limit cannot be bypassed by simultaneous requests.
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $paid = app(\App\Services\Saas\SaasEntitlements::class)->allows($locked, 'connect_store');
            $firstParty = app(\App\Services\Saas\SaasFirstPartyPilot::class)
                ->allowsStoreRegistration($locked, $url);
            if (!$paid && !$firstParty) {
                throw ValidationException::withMessages([
                    'base_url' => 'Your current plan has reached its store limit.',
                ]);
            }
            $locked->stores()->create([
                'name' => trim($data['name']),
                'base_url' => $url,
                'status' => 'pending_verification',
            ]);
        });

        return back()->with('status', 'Store saved for future Botble Connector pairing. It is not connected yet.');
    }

    public function removeStore(Request $request, SaasWorkspace $workspace, SaasBotbleStore $store): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        abort_unless($store->workspace_id === $workspace->id, 404);
        if ($store->status === 'connected') {
            abort(403, 'Connected stores must be revoked through the connector.');
        }
        $store->delete();

        return back()->with('status', 'Unpaired Botble store record removed.');
    }

    private function validatedStoreUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || ($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/'
            || !str_contains($host, '.')
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP)
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
            throw ValidationException::withMessages([
                'base_url' => 'Enter your public HTTPS Botble store domain (for example https://shop.example.com).',
            ]);
        }

        return 'https://'.$host;
    }
}
