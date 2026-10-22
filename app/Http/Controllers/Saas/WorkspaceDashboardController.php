<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceDashboardController extends Controller
{
    public function show(Request $request, SaasWorkspace $workspace, WorkspacePermissions $permissions): View
    {
        // All read models are explicitly anchored to this verified workspace.
        return view('saas.dashboard', [
            'workspace' => $workspace,
            'role' => $permissions->roleFor($request->user(), $workspace),
            'productCount' => $workspace->products()->count(),
            'storeCount' => $workspace->stores()->count(),
            'cjConnected' => $workspace->cjConnections()->where('status', 'connected')->exists(),
        ]);
    }
}
