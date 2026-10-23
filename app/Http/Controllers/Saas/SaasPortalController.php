<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\SaasCjCatalogBrowser;
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
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,draft,approved'],
        ]);
        $search = trim((string) ($input['q'] ?? ''));
        $status = $input['status'] ?? 'all';
        $query = $workspace->products();
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('title', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('cj_product_id', 'like', $like);
            });
        }
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('saas.products', $this->shared($request, $workspace) + [
            'products' => $query->latest()->paginate(15)->withQueryString(),
            'counts' => [
                'all' => $workspace->products()->count(),
                'draft' => $workspace->products()->where('status', 'draft')->count(),
            ],
            'connectedStores' => $workspace->stores()->where('status', 'connected')->count(),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function importCj(Request $request, SaasWorkspace $workspace): View
    {
        return view('saas.cj-import', $this->shared($request, $workspace) + [
            'canImportCj' => app(SaasCjCatalogBrowser::class)->canSearch($request->user(), $workspace),
            'cjPreview' => app(SaasCjCatalogBrowser::class)->preview($request->user(), $workspace, $request->query('cj_preview')),
            'cjPreviewKey' => (string) $request->query('cj_preview', ''),
            'cjSavedIds' => $workspace->products()->pluck('cj_product_id')->all(),
            'cjView' => $request->query('view') === 'list' ? 'list' : 'grid',
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
