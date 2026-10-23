@extends('balenco-data-pilot.layout')
@section('title', 'Customer access')
@section('content')
<div class="bdp-page-head">
    <div class="bdp-page-title">
        <h1>Customer access</h1>
        <p>Approve Data Pilot SaaS customers without relying on email verification. Access is restricted to platform administrators.</p>
    </div>
</div>
@if(session('success'))<div class="bdp-alert success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="bdp-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="bdp-card">
    <p>For complimentary, time-limited workspace access, open
        <a href="{{ route('bdp.saas.workspaces') }}">Workspace access grants</a>.
        Paid subscriptions are activated by verified Stripe webhooks, not by administrators.
    </p>
</div>
<div class="bdp-card">
    <form method="get" action="{{ route('bdp.saas.customers') }}" class="bdp-toolbar">
        <label for="customer-search">Search customer email</label>
        <input id="customer-search" name="q" type="search" value="{{ $search }}" maxlength="255" placeholder="Customer email" style="max-width:360px">
        <button class="bdp-btn" type="submit">Search</button>
    </form>
</div>
<div class="bdp-card">
    <h2 class="bdp-card-title">Customer accounts</h2>
    <p class="bdp-card-subtitle">Approving an account is an administrative authorization, not proof of email ownership. New accounts require a password and administrator approval.</p>
    <div class="bdp-table-wrap" style="margin-top:16px">
        <table class="bdp-table">
            <thead><tr><th>Customer</th><th>Access</th><th>Administrator action</th></tr></thead>
            <tbody>
            @forelse($customers as $customer)
                <tr>
                    <td>
                        <strong>{{ $customer->name }}</strong>
                        <div style="overflow-wrap:anywhere">{{ $customer->email }}</div>
                        <small>Account #{{ $customer->id }}</small>
                    </td>
                    <td>
                        @if($customer->saas_manually_approved_at)
                            <span class="bdp-pill success">Admin approved</span>
                        @elseif($customer->hasVerifiedEmail())
                            <span class="bdp-pill success">Email verified</span>
                        @else
                            <span class="bdp-pill warning">Pending activation</span>
                        @endif
                        @if($customer->saas_password_change_required)
                            <div><span class="bdp-pill info">Password change required</span></div>
                        @endif
                    </td>
                    <td style="min-width:270px">
                        <form method="post" action="{{ route('bdp.saas.customers.approve', $customer) }}" autocomplete="off">
                            @csrf
                            <label for="temp-pass-{{ $customer->id }}">Set temporary password (optional)</label>
                            <input id="temp-pass-{{ $customer->id }}" name="password" type="password" minlength="12" maxlength="255" autocomplete="new-password" placeholder="Leave blank to approve only">
                            <label for="temp-confirm-{{ $customer->id }}" style="margin-top:8px">Confirm temporary password</label>
                            <input id="temp-confirm-{{ $customer->id }}" name="password_confirmation" type="password" minlength="12" maxlength="255" autocomplete="new-password">
                            <small>12+ characters, uppercase, lowercase and numbers. Never send temporary passwords through unsecured email. Users must change an admin-set password at login.</small>
                            <button class="bdp-btn" type="submit" style="margin-top:10px" onclick="return confirm('Approve this account and optionally replace its password?')">Approve / update password</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">No customer accounts found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px">{{ $customers->links() }}</div>
</div>
@endsection
