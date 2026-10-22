<?php

namespace App\Http\Middleware;

use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasEntitlement
{
    public function __construct(private readonly SaasEntitlements $entitlements)
    {
    }

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $workspace = $request->route('workspace');
        abort_unless($workspace instanceof SaasWorkspace, 404);
        abort_unless($request->attributes->get('saas_workspace_id') === $workspace->id, 403);
        $paid = $this->entitlements->allows($workspace, $action);
        // Only the dedicated Botble registration POST may use the first-party
        // pilot. Other subscription features continue to require real billing.
        $internalPilot = $action === 'manage_connection'
            && $request->routeIs('saas.workspace.connections.botble.add')
            && app(\App\Services\Saas\SaasFirstPartyPilot::class)->allowsStoreRegistration(
                $workspace, (string) $request->input('base_url', '')
            );
        abort_unless($paid || $internalPilot, 403, 'Active subscription required or usage limit reached.');

        return $next($request);
    }
}
