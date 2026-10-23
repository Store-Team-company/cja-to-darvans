<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasAdminPlanGrant;
use App\Models\SaasWorkspace;
use App\Services\Saas\SaasEntitlements;
use App\Services\Saas\SaasPlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SaasAdminPlanGrantController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(config('saas.admin_grants_enabled'), 404);
        $search = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($search) > 150, 422);

        $workspaces = SaasWorkspace::query()
            ->with(['billing'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderByDesc('id')
            ->paginate(15)->withQueryString();

        return view('balenco-data-pilot.saas-workspaces', [
            'workspaces' => $workspaces,
            'grants' => SaasAdminPlanGrant::query()
                ->whereIn('workspace_id', $workspaces->pluck('id'))->get()->keyBy('workspace_id'),
            'plans' => app(SaasPlanCatalog::class)->all(),
            'search' => $search,
        ]);
    }

    public function grant(Request $request, SaasWorkspace $workspace, SaasEntitlements $entitlements): RedirectResponse
    {
        abort_unless(config('saas.admin_grants_enabled'), 404);
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(app(SaasPlanCatalog::class)->all()))],
            'days' => ['required', 'integer', 'min:1', 'max:90'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
            'confirmation' => ['required', 'in:GRANT'],
        ]);

        DB::transaction(function () use ($workspace, $entitlements, $data): void {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active' && !$entitlements->paidActive($locked), 422,
                'Workspace must be active and cannot already have a paid Stripe subscription.');

            $grant = SaasAdminPlanGrant::query()->where('workspace_id', $locked->id)
                ->lockForUpdate()->first()
                ?? new SaasAdminPlanGrant();
            $grant->workspace_id = $locked->id;
            $grant->plan_code = $data['plan'];
            $grant->starts_at = now();
            $grant->expires_at = now()->addDays((int) $data['days']);
            $grant->revoked_at = null;
            $grant->reason = trim($data['reason']);
            $grant->granted_by = 'authenticated_bdp_admin';
            $grant->save();

            DB::table('saas_admin_plan_grant_events')->insert([
                'workspace_id' => $locked->id,
                'action' => 'granted',
                'plan_code' => $grant->plan_code,
                'expires_at' => $grant->expires_at,
                'reason' => $grant->reason,
                'actor' => 'authenticated_bdp_admin',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'Complimentary workspace access enabled. No Stripe payment was created or marked paid.');
    }

    public function revoke(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        abort_unless(config('saas.admin_grants_enabled'), 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:255'],
            'confirmation' => ['required', 'in:REVOKE'],
        ]);

        DB::transaction(function () use ($workspace, $data): void {
            $locked = SaasWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $grant = SaasAdminPlanGrant::query()
                ->where('workspace_id', $locked->id)->lockForUpdate()->first();
            abort_unless($grant && !$grant->revoked_at && $grant->expires_at->isFuture(), 422);

            $grant->revoked_at = now();
            $grant->save();

            DB::table('saas_admin_plan_grant_events')->insert([
                'workspace_id' => $locked->id,
                'action' => 'revoked',
                'plan_code' => $grant->plan_code,
                'expires_at' => $grant->expires_at,
                'reason' => trim($data['reason']),
                'actor' => 'authenticated_bdp_admin',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'Complimentary access revoked; Stripe subscription records were not modified.');
    }
}
