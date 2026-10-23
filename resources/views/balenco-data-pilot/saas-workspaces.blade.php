@extends('balenco-data-pilot.layout')
@section('title', 'Workspace access grants')
@section('content')
<div class="bdp-page-head"><div class="bdp-page-title">
<h1>Workspace access grants</h1>
<p>Limited complimentary access, manually authorized by a platform administrator. These grants are never Stripe payments.</p>
</div></div>
@if(session('success'))<div class="bdp-alert success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="bdp-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="bdp-card">
<p>Paid subscriptions use verified Stripe checkout and webhook confirmation. Customers can subscribe in Billing & Plans after Stripe configuration is complete. Complimentary access expires automatically.</p>
<form method="get" action="{{ route('bdp.saas.workspaces') }}" class="bdp-toolbar">
<label for="workspace-q">Find a workspace</label>
<input name="q" id="workspace-q" value="{{ $search }}" maxlength="150" placeholder="Workspace name">
<button class="bdp-btn" type="submit">Search</button>
</form>
</div>
<div class="bdp-card"><h2 class="bdp-card-title">Workspace grants</h2>
<div class="bdp-table-wrap"><table class="bdp-table">
<thead><tr><th>Workspace</th><th>Access</th><th>Admin action</th></tr></thead><tbody>
@forelse($workspaces as $workspace)
@php($grant = $grants->get($workspace->id))
@php($grantActive = $grant && !$grant->revoked_at && $grant->starts_at->lte(now()) && $grant->expires_at->gt(now()) && $workspace->status === 'active')
<tr>
<td><strong>{{ $workspace->name }}</strong><div class="micro">{{ $workspace->public_id }}</div><div class="micro">Workspace: {{ $workspace->status }}</div></td>
<td>
@if($workspace->billing && in_array($workspace->billing->status, ['active','trialing'], true))
<span class="bdp-pill success">Stripe record: {{ $workspace->billing->status }}</span>
@endif
@if($grantActive)
<span class="bdp-pill success">Complimentary {{ $grant->plan_code }}</span>
<div>Expires {{ $grant->expires_at->format('Y-m-d') }}</div>
@else
<span class="bdp-pill warning">No active admin grant</span>
@endif
</td>
<td style="min-width:300px">
<form method="post" action="{{ route('bdp.saas.workspaces.grant', $workspace->public_id) }}">@csrf
<label>Plan</label><select name="plan" required>
@foreach($plans as $code=>$plan)<option value="{{ $code }}">{{ $plan['name'] }}</option>@endforeach
</select>
<label>Duration in days (1–90)</label><input name="days" type="number" min="1" max="90" value="14" required>
<label>Reason (audit record)</label><input name="reason" required minlength="8" maxlength="255" placeholder="Internal staging evaluation">
<input type="hidden" name="confirmation" value="GRANT">
<button class="bdp-btn" type="submit" onclick="return confirm('Grant limited complimentary access without charging Stripe?')">Grant complimentary access</button>
</form>
@if($grantActive)
<form method="post" action="{{ route('bdp.saas.workspaces.revoke', $workspace->public_id) }}" style="margin-top:10px">@csrf
<label>Reason for revocation</label><input name="reason" required minlength="8" maxlength="255" placeholder="Staging period completed">
<input type="hidden" name="confirmation" value="REVOKE">
<button class="bdp-btn" type="submit" onclick="return confirm('Revoke this complimentary grant immediately?')">Revoke grant</button>
</form>
@endif
</td></tr>
@empty<tr><td colspan="3">No workspaces found.</td></tr>
@endforelse
</tbody></table></div><div style="margin-top:16px">{{ $workspaces->links() }}</div></div>
@endsection
