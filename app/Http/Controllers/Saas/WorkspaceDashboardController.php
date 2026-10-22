<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceDashboardController extends Controller
{
    public function show(Request $request, SaasWorkspace $workspace, WorkspacePermissions $permissions, SaasEntitlements $entitlements): View
    {
        $cjConnected = $workspace->cjConnections()->where('status', 'connected')->exists();
        $storeConnected = $workspace->stores()->where('status', 'connected')->exists();
        $active = $entitlements->active($workspace);
        $checks = [
            ['label' => 'Verify your email', 'done' => $request->user()->hasVerifiedEmail(), 'url' => null],
            ['label' => 'Activate a monthly subscription', 'done' => $active, 'url' => route('saas.workspace.billing', $workspace->public_id)],
            ['label' => 'Connect your CJ account', 'done' => $cjConnected, 'url' => route('saas.workspace.connections', $workspace->public_id)],
            ['label' => 'Pair your Botble store', 'done' => $storeConnected, 'url' => route('saas.workspace.connections', $workspace->public_id)],
            ['label' => 'Prepare your first product', 'done' => $workspace->products()->exists(), 'url' => route('saas.workspace.products', $workspace->public_id)],
        ];

        return view('saas.dashboard', [
            'workspace' => $workspace,
            'role' => $permissions->roleFor($request->user(), $workspace),
            'productCount' => $workspace->products()->count(),
            'storeCount' => $workspace->stores()->count(),
            'cjConnected' => $cjConnected,
            'storeConnected' => $storeConnected,
            'subscription' => $entitlements->billing($workspace),
            'activeSubscription' => $active,
            'steps' => $checks,
            'readySteps' => count(array_filter($checks, fn (array $item) => $item['done'])),
            'recentProducts' => $workspace->products()->latest()->limit(5)->get(),
        ]);
    }
}
