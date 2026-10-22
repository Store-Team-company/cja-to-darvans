@extends('balenco-data-pilot.layout')
@section('title', 'Manage Customers')
@section('content')
<div class="bdp-page-head" style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap">
    <div><h1 style="margin-bottom:5px">Manage Customers</h1><p style="color:#64748b">SaaS workspaces, account owners, verification and subscription status.</p></div>
    <span class="bdp-pill info">Platform administration · Customer data</span>
</div>

<div class="bdp-grid-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:22px 0">
    <div class="bdp-card" style="padding:18px"><small>Total workspaces</small><h2 style="font-size:28px;margin:5px 0">{{ $totalWorkspaces }}</h2></div>
    <div class="bdp-card" style="padding:18px"><small>Active access</small><h2 style="font-size:28px;margin:5px 0">{{ $activeWorkspaces }}</h2></div>
    <div class="bdp-card" style="padding:18px"><small>Pending</small><h2 style="font-size:28px;margin:5px 0">{{ $pendingWorkspaces }}</h2></div>
    <div class="bdp-card" style="padding:18px"><small>Suspended</small><h2 style="font-size:28px;margin:5px 0">{{ $suspendedWorkspaces }}</h2></div>
</div>

<div class="bdp-card" style="padding:20px">
    <form method="get" action="{{ route('bdp.customers.index') }}" class="bdp-toolbar" style="margin-bottom:18px;display:flex;gap:12px;align-items:end">
        <div style="flex:1;min-width:230px"><label for="customer_search">Search customers</label>
            <input id="customer_search" type="search" name="q" maxlength="150" value="{{ $q }}" placeholder="Workspace, email, customer name or ID"></div>
        <div style="min-width:155px"><label for="customer_status">Workspace status</label>
            <select id="customer_status" name="status">
                @foreach(['all'=>'All','active'=>'Active','pending'=>'Pending','suspended'=>'Suspended'] as $value=>$label)
                <option value="{{ $value }}" @selected($filterStatus === $value)>{{ $label }}</option>
                @endforeach
            </select></div>
        <button class="bdp-btn" type="submit">Search</button>
        <a class="bdp-btn light" href="{{ route('bdp.customers.index') }}">Clear</a>
    </form>
    <div class="bdp-table-wrap"><table class="bdp-table">
        <thead><tr><th>Workspace</th><th>Owner</th><th>Email verification</th><th>Plan / billing</th><th>Activity</th><th>Access</th><th></th></tr></thead>
        <tbody>
        @forelse($workspaces as $workspace)
        @php $owner = $workspace->members->first(); @endphp
        <tr>
            <td><a style="font-weight:800" href="{{ route('bdp.customers.show', $workspace->public_id) }}">{{ $workspace->name }}</a>
                <div class="bdp-input-help">{{ $workspace->public_id }}</div></td>
            <td>{{ $owner?->name ?? 'No active owner' }}
                <div class="bdp-input-help">{{ $owner?->email ?? '—' }}</div></td>
            <td><span class="bdp-pill {{ $owner?->email_verified_at ? 'success' : 'warning' }}">{{ $owner?->email_verified_at ? 'Verified' : 'Unverified' }}</span></td>
            <td><strong>{{ ucfirst($workspace->billing?->plan_code ?? 'No plan') }}</strong>
                <div class="bdp-input-help">{{ ucfirst($workspace->billing?->status ?? 'Not subscribed') }}</div></td>
            <td>{{ $workspace->products_count }} products · {{ $workspace->stores_count }} stores
                <div class="bdp-input-help">{{ $workspace->members_count }} members</div></td>
            <td><span class="bdp-pill {{ $workspace->status === 'suspended' ? 'danger' : ($workspace->status === 'active' ? 'success' : 'warning') }}">{{ ucfirst($workspace->status) }}</span></td>
            <td><a class="bdp-btn light" href="{{ route('bdp.customers.show', $workspace->public_id) }}">View</a></td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b">No matching customers. Try another name or email.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:16px">{{ $workspaces->links() }}</div>
</div>
@endsection
