@extends('balenco-data-pilot.layout')
@section('title', 'Customer Workspace')
@section('content')
<div style="margin-bottom:18px"><a href="{{ route('bdp.customers.index') }}" style="color:#315ac0">← Back to customers</a></div>
<div class="bdp-page-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div><small>Customer workspace</small><h1>{{ $workspace->name }}</h1><p class="bdp-input-help">ID: {{ $workspace->public_id }} · Created {{ $workspace->created_at->format('M j, Y') }}</p></div>
    <span class="bdp-pill {{ $workspace->status === 'suspended' ? 'danger' : 'info' }}">{{ ucfirst($workspace->status) }}</span>
</div>
<div class="bdp-grid-3" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px;margin:22px 0">
    <div class="bdp-card" style="padding:20px"><small>Products</small><h2>{{ $workspace->products_count }}</h2></div>
    <div class="bdp-card" style="padding:20px"><small>Botble stores</small><h2>{{ $workspace->stores_count }}</h2></div>
    <div class="bdp-card" style="padding:20px"><small>Team members</small><h2>{{ $workspace->members_count }}</h2></div>
</div>
<div class="bdp-grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px">
    <section class="bdp-card" style="padding:22px">
        <h2>Billing summary</h2>
        <div class="bdp-info-row"><strong>Plan</strong><span>{{ ucfirst($workspace->billing?->plan_code ?? 'None') }}</span></div>
        <div class="bdp-info-row"><strong>Stripe subscription</strong><span>{{ ucfirst($workspace->billing?->status ?? 'Not linked') }}</span></div>
        <div class="bdp-info-row"><strong>Period end</strong><span>{{ $workspace->billing?->current_period_end?->format('M j, Y') ?? '—' }}</span></div>
        <div class="bdp-info-row"><strong>Cancel at period end</strong><span>{{ $workspace->billing?->cancel_at_period_end ? 'Yes' : 'No' }}</span></div>
        <p class="bdp-input-help" style="margin-top:12px">Subscription status comes from Stripe webhooks. This admin page does not grant plans or change billing records.</p>
    </section>
    <section class="bdp-card" style="padding:22px">
        <h2>Store connections</h2>
        <div class="bdp-info-row"><strong>CJ API status</strong><span>{{ str_replace('_', ' ', ucfirst($cj?->status ?? 'Not configured')) }}</span></div>
        @forelse($workspace->stores as $store)
        <div class="bdp-info-row">
            <div><strong>{{ $store->name }}</strong><div class="bdp-input-help">{{ $store->base_url }}</div></div>
            <span class="bdp-pill {{ $store->status === 'connected' ? 'success' : 'warning' }}">{{ ucfirst($store->status) }}</span>
        </div>
        @empty<p class="bdp-input-help">No Botble stores registered.</p>
        @endforelse
        <p class="bdp-input-help">API keys and connector signing secrets are intentionally hidden.</p>
    </section>
    <section class="bdp-card" style="padding:22px">
        <h2>Customer team</h2>
        <div class="bdp-table-wrap"><table class="bdp-table"><thead><tr><th>Member</th><th>Role</th><th>Verified</th><th>Access</th></tr></thead><tbody>
        @foreach($workspace->members as $member)
            <tr><td>{{ $member->name }}<div class="bdp-input-help">{{ $member->email }}</div></td>
            <td>{{ ucfirst($member->pivot->role) }}</td>
            <td>{{ $member->email_verified_at ? 'Yes' : 'No' }}</td>
            <td>{{ ucfirst($member->pivot->status) }}</td></tr>
        @endforeach
        </tbody></table></div>
    </section>
    <section class="bdp-card" style="padding:22px">
        <h2>Workspace access</h2>
        @if($workspace->status === 'suspended')
            <p>Suspended workspaces cannot access tenant pages. Restoring access does not change Stripe payment status.</p>
            <form method="post" action="{{ route('bdp.customers.restore', $workspace->public_id) }}">
                @csrf<button class="bdp-btn" type="submit">Restore previous access state</button>
            </form>
        @else
            <p>Suspending a workspace blocks its member dashboard and active import entitlements. Stripe billing is managed separately.</p>
            <form method="post" action="{{ route('bdp.customers.suspend', $workspace->public_id) }}">
                @csrf
                <label for="suspend_reason">Reason for suspension (required)</label>
                <textarea id="suspend_reason" name="reason" minlength="5" maxlength="500" required>{{ old('reason') }}</textarea>
                @error('reason')<p style="color:#b91c1c">{{ $message }}</p>@enderror
                <button class="bdp-btn orange" type="submit">Suspend workspace</button>
            </form>
        @endif
    </section>
</div>
<section class="bdp-card" style="padding:22px;margin-top:18px">
    <h2>Administrative activity</h2>
    <div class="bdp-table-wrap"><table class="bdp-table"><thead><tr><th>Action</th><th>State</th><th>Reason</th><th>When</th></tr></thead><tbody>
    @forelse($audit as $event)
        <tr><td>{{ str_replace('_', ' ', $event->action) }}</td>
            <td>{{ $event->from_status }} → {{ $event->to_status }}</td>
            <td>{{ $event->reason ?: '—' }}</td>
            <td>{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('M j, Y H:i') }}</td></tr>
    @empty<tr><td colspan="4">No administrative status changes recorded.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
