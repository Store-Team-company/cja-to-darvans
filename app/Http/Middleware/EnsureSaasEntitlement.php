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
        abort_unless($this->entitlements->allows($workspace, $action), 403, 'Active subscription required or usage limit reached.');

        return $next($request);
    }
}
