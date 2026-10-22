<?php

namespace App\Http\Middleware;

use App\Models\SaasWorkspace;
use App\Services\Saas\WorkspacePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasWorkspaceMember
{
    public function __construct(private readonly WorkspacePermissions $permissions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        // Respond with 404 for cross-tenant identifiers to avoid disclosing
        // another merchant's workspace, products, or connection existence.
        abort_unless($workspace instanceof SaasWorkspace && $request->user(), 404);
        abort_unless($this->permissions->allows($request->user(), $workspace, 'view'), 404);
        abort_if($workspace->status === 'suspended', 403);

        // Do not infer tenant ID from body, session, or untrusted request headers.
        $request->attributes->set('saas_workspace_id', $workspace->id);

        return $next($request);
    }
}
