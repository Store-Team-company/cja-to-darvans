@extends('saas.layout')
@section('title', 'Subscription & Billing')
@section('content')
<div class="page-title"><span class="eyebrow">Workspace subscription</span><h1>Billing & Plans</h1><p>Your monthly plan belongs to your workspace, not an individual team member.</p></div>
@if(request('checkout') === 'success')
<div class="alert ok">You returned from Stripe. Payment and access are confirmed only when the signed Stripe webhook updates your subscription. Refresh this page shortly to see its verified status.</div>
@elseif(request('checkout') === 'cancel')
<div class="alert">You canceled checkout. No new subscription was activated here.</div>
@endif
<div class="card split" style="flex-wrap:wrap;align-items:flex-start;margin-bottom:25px">
    <div>
        <div class="micro">Subscription status</div>
        <h2 style="margin:8px 0"><span class="tag {{ $activeSubscription ? 'good' : 'warn' }}">{{ $activeSubscription ? 'Active' : ucfirst($bill?->status ?? 'Not subscribed') }}</span></h2>
        <p style="margin:0">Plan: <strong>{{ ucfirst($bill?->plan_code ?? 'None') }}</strong>
            @if($bill?->current_period_end) · Current period ends {{ $bill->current_period_end->format('M j, Y') }} @endif
            @if($bill?->cancel_at_period_end) · Scheduled to cancel @endif
        </p>
    </div>
    @if($bill?->stripe_customer_id && $role === 'owner')
    <form action="{{ route('saas.workspace.billing.portal', $workspace->public_id) }}" method="post">@csrf<button type="submit" class="btn">Manage subscription in Stripe →</button></form>
    @endif
</div>
@if(!config('saas.billing_enabled'))<div class="alert">Stripe billing is not enabled on this installation. Prices below are proposed, not yet available for purchase.</div>@endif
<h2>Choose a plan</h2><p>All plans are monthly. Team members without billing permission cannot start checkout.</p>
<div class="grid">
@foreach($plans as $code => $plan)
<div class="card plan-card {{ $code === 'growth' ? 'plan-featured' : '' }}">
    <div class="split"><h3>{{ $plan['name'] }}</h3>@if($code === 'growth')<span class="tag good">Popular</span>@endif</div>
    <div class="plan-price">{{ $plan['price_label'] }} <small>/ month</small></div>
    <ul><li>{{ number_format($plan['product_limit']) }} products</li><li>{{ $plan['store_limit'] }} Botble {{ $plan['store_limit'] === 1 ? 'store' : 'stores' }}</li><li>{{ $plan['seat_limit'] }} team seats</li><li>{{ $plan['sync_label'] }} (upcoming)</li></ul>
    @if($role === 'owner' && !$activeSubscription && $planCatalog->checkoutReady($code))
        <form method="post" action="{{ route('saas.workspace.billing.checkout', $workspace->public_id) }}">@csrf<input type="hidden" name="plan" value="{{ $code }}"><button class="btn {{ $code === 'growth' ? 'btn-accent' : '' }}" style="width:100%" type="submit">Subscribe with Stripe →</button></form>
    @elseif($activeSubscription && $bill?->plan_code === $code)
        <span class="tag good">Your current plan</span>
    @else<button class="btn" style="width:100%" disabled>{{ $role !== 'owner' ? 'Owner permission required' : ($activeSubscription ? 'Manage in Stripe portal' : 'Checkout not available yet') }}</button>@endif
</div>
@endforeach
</div>
<p class="micro mt">Access to CJ product import and Botble publishing additionally requires an approved integration release. An active subscription alone does not enable unfinished endpoints.</p>
@endsection
