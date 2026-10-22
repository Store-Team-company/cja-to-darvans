<?php

namespace App\Http\Controllers;

use App\Models\SaasWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BdpCustomerManagementController extends Controller
{
    /**
     * Legacy /login is the *only* authentication method for these routes.
     * Never authorize platform administration from SaaS memberships.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $query = SaasWorkspace::query()
            ->with(['billing' => fn ($q) => $q->select(
                'id', 'workspace_id', 'plan_code', 'status', 'current_period_end',
                'cancel_at_period_end'
            ), 'members' => fn ($q) => $q->wherePivot('role', 'owner')
                ->wherePivot('status', 'active')
                ->select('users.id', 'users.name', 'users.email', 'users.email_verified_at')])
            ->withCount(['members', 'products', 'stores'])
            ->orderByDesc('created_at')->orderByDesc('id');

        if (in_array($status, ['pending', 'active', 'suspended'], true)) {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('public_id', 'like', '%'.$search.'%')
                    ->orWhereHas('members', fn ($u) => $u
                        ->where('users.email', 'like', '%'.$search.'%')
                        ->orWhere('users.name', 'like', '%'.$search.'%'));
            });
        }

        return view('balenco-data-pilot.customers.index', [
            'workspaces' => $query->paginate(20)->withQueryString(),
            'q' => $search,
            'filterStatus' => $status,
            'totalWorkspaces' => SaasWorkspace::count(),
            'activeWorkspaces' => SaasWorkspace::where('status', 'active')->count(),
            'pendingWorkspaces' => SaasWorkspace::where('status', 'pending')->count(),
            'suspendedWorkspaces' => SaasWorkspace::where('status', 'suspended')->count(),
        ]);
    }

    public function show(SaasWorkspace $workspace): View
    {
        // Select explicit columns, excluding encrypted CJ tokens, Botble
        // shared secrets, checkout URLs, personal passwords and OTP codes.
        $workspace->load([
            'members' => fn ($q) => $q
                ->select('users.id', 'users.name', 'users.email', 'users.email_verified_at')
                ->orderBy('users.name'),
            'stores' => fn ($q) => $q->select(
                'id', 'workspace_id', 'public_id', 'name', 'base_url',
                'status', 'last_verified_at', 'created_at'
            )->orderBy('name'),
            'billing' => fn ($q) => $q->select(
                'id', 'workspace_id', 'plan_code', 'status',
                'stripe_price_id', 'current_period_end', 'cancel_at_period_end'
            ),
        ])->loadCount(['products', 'stores', 'members']);

        $cj = DB::table('saas_cj_connections')
            ->where('workspace_id', $workspace->id)
            ->first(['status', 'last_verified_at']);

        $audit = DB::table('bdp_customer_admin_audit')
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')->limit(15)
            ->get(['action', 'from_status', 'to_status', 'actor', 'reason', 'created_at']);

        return view('balenco-data-pilot.customers.show', compact('workspace', 'cj', 'audit'));
    }

    public function suspend(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        DB::transaction(function () use ($workspace, $data, $request): void {
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'suspended') {
                return;
            }
            $from = $locked->status;
            $locked->status = 'suspended';
            $locked->save();
            $this->audit($request, $locked, 'workspace.suspended', $from, 'suspended', trim($data['reason']));
        });

        return redirect()->route('bdp.customers.show', $workspace->public_id)
            ->with('success', 'Workspace suspended. This does NOT cancel any Stripe subscription; review billing separately.');
    }

    public function restore(Request $request, SaasWorkspace $workspace): RedirectResponse
    {
        DB::transaction(function () use ($workspace, $request): void {
            $locked = SaasWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'suspended') {
                return;
            }
            $old = DB::table('bdp_customer_admin_audit')
                ->where('workspace_id', $locked->id)
                ->where('action', 'workspace.suspended')
                ->orderByDesc('id')
                ->value('from_status');
            $target = in_array($old, ['active', 'pending'], true) ? $old : 'pending';
            $locked->status = $target;
            $locked->save();
            $this->audit($request, $locked, 'workspace.restored', 'suspended', $target, null);
        });

        return redirect()->route('bdp.customers.show', $workspace->public_id)
            ->with('success', 'Workspace restored to its previous access state. Billing eligibility is unchanged.');
    }

    private function audit(
        Request $request, SaasWorkspace $workspace, string $action,
        string $from, string $to, ?string $reason
    ): void {
        DB::table('bdp_customer_admin_audit')->insert([
            'workspace_id' => $workspace->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            // Legacy BDP admin is not a customer User. Never accept
            // identity or role from a request field.
            'actor' => (string) $request->session()->get('bdp_admin_username', 'legacy-admin'),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
