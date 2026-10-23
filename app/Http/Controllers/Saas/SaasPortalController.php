<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\SaasCjProductDraftImporter;
use App\Services\Saas\WorkspacePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaasPortalController extends Controller
{
    private function shared(Request $request, SaasWorkspace $workspace): array
    {
        return [
            'workspace' => $workspace,
            'role' => app(WorkspacePermissions::class)->roleFor($request->user(), $workspace),
            'activeSubscription' => app(SaasEntitlements::class)->active($workspace),
            'accessSource' => app(SaasEntitlements::class)->accessSource($workspace),
        ];
    }

    public function products(Request $request, SaasWorkspace $workspace): View
    {
        return view('saas.products', $this->shared($request, $workspace) + [
            'products' => $workspace->products()->latest()->paginate(15),
            'canImportCj' => app(SaasCjProductDraftImporter::class)->canImport($request->user(), $workspace),
        ]);
    }

    public function connections(Request $request, SaasWorkspace $workspace): View
    {
        return view('saas.connections', $this->shared($request, $workspace) + [
            'cjConnection' => $workspace->cjConnections()->first(),
            'stores' => $workspace->stores()->latest()->get(),
        ]);
    }

    public function team(Request $request, SaasWorkspace $workspace): View
    {
        return view('saas.team', $this->shared($request, $workspace) + [
            'members' => $workspace->members()->orderBy('name')->get(),
        ]);
    }

    public function settings(Request $request, SaasWorkspace $workspace): View
    {
        return view('saas.settings', $this->shared($request, $workspace));
    }

    public function updateSettings(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        app(WorkspacePermissions::class)->authorize($request->user(), $workspace, 'manage_team');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:150']]);
        $workspace->update(['name' => trim($data['name'])]);

        return back()->with('status', 'Workspace name updated.');
    }
}
