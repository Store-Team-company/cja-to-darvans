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
    private function cjAuthorized(SaasWorkspace $workspace): bool
    {
        return app(\App\Services\Saas\SaasEntitlements::class)->allows($workspace, 'connect_cj')
            || app(\App\Services\Saas\SaasCjPilot::class)->allows($workspace);
    }

    public function saveCj(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        abort_unless($this->cjAuthorized($workspace), 403);

        // Never flash an API key to Laravel's old-input session on validation
        // failure (it is not in Laravel's default password exclusion list).
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'api_key' => ['required', 'string', 'min:16', 'max:200'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except('api_key'));
        }
        $data = $validator->validated();
        DB::transaction(function () use ($workspace, $data): void {
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->cjAuthorized($locked), 403);

            $connection = $locked->cjConnections()->firstOrNew([]);
            $connection->api_key = trim($data['api_key']);
            $connection->access_token = null;
            $connection->refresh_token = null;
            $connection->access_token_expires_at = null;
            $connection->refresh_token_expires_at = null;
            $connection->status = 'pending_verification';
            $connection->last_verified_at = null;
            $connection->last_error_code = null;
            // Keep last_auth_attempt_at, so replacing a key cannot evade CJ throttling.
            $connection->save();
        });

        return back()->with('status', 'CJ credentials encrypted and saved. Use Test connection to verify access.');
    }

    public function verifyCj(
        Request $request,
        SaasWorkspace $workspace,
        \App\Services\Saas\SaasCjApiClient $client
    ): RedirectResponse {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        abort_unless($this->cjAuthorized($workspace), 403);

        // Serialize authorization reservations per workspace without holding a
        // database lock over the remote HTTPS request.
        $reservation = DB::transaction(function () use ($workspace): array {
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->cjAuthorized($locked), 403);
            $connection = $locked->cjConnections()->lockForUpdate()->first();
            if (!$connection || !$connection->api_key) {
                return ['error' => 'missing'];
            }
            if ($connection->last_auth_attempt_at && $connection->last_auth_attempt_at->gt(now()->subMinute())) {
                return ['error' => 'cooldown'];
            }

            $connection->last_auth_attempt_at = now();
            $connection->save();
            $refresh = $connection->access_token
                && $connection->access_token_expires_at
                && $connection->access_token_expires_at->lte(now()->addDay())
                && $connection->refresh_token
                && $connection->refresh_token_expires_at?->gt(now()->addDay());

            return [
                'connection_id' => $connection->id,
                'api_key' => $connection->api_key,
                'refresh_token' => $refresh ? $connection->refresh_token : null,
            ];
        });

        if (isset($reservation['error'])) {
            return back()->withErrors(['cj' => $reservation['error'] === 'cooldown'
                ? 'CJ verification was attempted recently. Try again in one minute.'
                : 'Save a CJ API key before testing its connection.']);
        }

        // CJ documents 1 QPS authentication and server-side token caching.
        // Shared lock plus 2-second global cache gate prevents simultaneous
        // authentication from multiple workspaces on the shared hosting IP.
        $lock = \Illuminate\Support\Facades\Cache::lock('datapilot-cj-auth-global', 20);
        if (!$lock->get()) {
            return back()->withErrors(['cj' => 'CJ authentication is busy. Please retry in one minute.']);
        }
        try {
            if (!\Illuminate\Support\Facades\Cache::add('datapilot-cj-auth-cooldown', true, 2)) {
                return back()->withErrors(['cj' => 'CJ authentication is rate-limited. Please retry shortly.']);
            }
            try {
                $tokens = $reservation['refresh_token']
                    ? $client->refresh($reservation['refresh_token'])
                    : $client->authorize($reservation['api_key']);
                $problem = null;
            } catch (\App\Services\Saas\SaasCjApiException $e) {
                $tokens = null;
                $problem = $e;
            }
        } finally {
            $lock->release();
        }

        $updated = DB::transaction(function () use ($workspace, $reservation, $tokens, $problem): bool {
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $connection = $locked->cjConnections()->lockForUpdate()->first();
            if (!$connection || $connection->id !== $reservation['connection_id']
                || !$connection->api_key
                || !hash_equals($reservation['api_key'], $connection->api_key)) {
                return false; // Credential rotated/removed during remote request.
            }
            if ($problem) {
                $connection->status = 'failed';
                $connection->last_error_code = $problem->reason;
                $connection->access_token = null;
                $connection->refresh_token = null;
                $connection->access_token_expires_at = null;
                $connection->refresh_token_expires_at = null;
            } else {
                $connection->fill($tokens);
                $connection->status = 'connected';
                $connection->last_error_code = null;
                $connection->last_verified_at = now();
            }
            $connection->save();

            return true;
        });

        if (!$updated) {
            return back()->withErrors(['cj' => 'Credentials changed while connecting. Test the latest saved key.']);
        }

        return $problem
            ? back()->withErrors(['cj' => $problem->safeMessage()])
            : back()->with('status', 'CJ authentication succeeded. Your connection is now verified.');
    }

    public function removeCj(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_connections');
        DB::transaction(function () use ($workspace): void {
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $connection = $locked->cjConnections()->lockForUpdate()->first();
            if ($connection) {
                $connection->api_key = null;
                $connection->access_token = null;
                $connection->refresh_token = null;
                $connection->access_token_expires_at = null;
                $connection->refresh_token_expires_at = null;
                $connection->status = 'disconnected';
                $connection->last_verified_at = null;
                $connection->last_error_code = null;
                $connection->save();
            }
        });

        return back()->with('status', 'CJ credentials and tokens securely removed from this workspace.');
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
