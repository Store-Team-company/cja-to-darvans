@extends('saas.layout')
@section('title', 'Overview')
@section('content')
<div class="split page-title"><div><span class="eyebrow">Your workspace</span><h1>Welcome back, {{ auth()->user()->name }}</h1><p>Manage your product workflow and see what needs your attention.</p></div><a href="{{ route('saas.workspace.billing', $workspace->public_id) }}" class="btn">View plan →</a></div>
<div class="grid">
    <div class="card stat"><span class="subtle">Products</span><b>{{ $productCount }}</b><small>Imported product records</small></div>
    <div class="card stat"><span class="subtle">Botble stores</span><b>{{ $storeCount }}</b><small>{{ $storeConnected ? 'At least one paired store' : 'No store paired yet' }}</small></div>
    <div class="card stat"><span class="subtle">CJ connection</span><b style="font-size:20px;margin:20px 0">{{ $cjConnected ? 'Connected' : 'Not connected' }}</b><small>Account-specific CJ API</small></div>
</div>
<div class="grid section" style="grid-template-columns:repeat(auto-fit,minmax(310px,1fr))">
    <div class="card">
        <div class="split"><div><h2 style="margin-bottom:5px">Get started</h2><p style="margin-bottom:14px">Complete your setup in order.</p></div><span class="tag">{{ $readySteps }} / {{ count($steps) }}</span></div>
        <div class="progress" role="progressbar" aria-label="Setup completion" aria-valuenow="{{ $readySteps }}" aria-valuemin="0" aria-valuemax="{{ count($steps) }}"><span style="width:{{ $readySteps / max(count($steps), 1) * 100 }}%"></span></div>
        <ul class="steps">
        @foreach($steps as $step)
            <li><span class="step-mark {{ $step['done'] ? 'done' : '' }}">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                <span style="flex:1">{{ $step['label'] }}</span>
                @if(!$step['done'] && $step['url'])<a href="{{ $step['url'] }}">Details →</a>@endif
            </li>
        @endforeach
        </ul>
    </div>
    <div class="card">
        <div class="split"><h2>Subscription</h2><span class="tag {{ $activeSubscription ? 'good' : 'warn' }}">{{ $activeSubscription ? 'Active' : 'Inactive' }}</span></div>
        <p>{{ $activeSubscription ? 'Your subscription is verified with Stripe.' : 'Choose a plan to prepare your store integrations.' }}</p>
        <div class="card" style="box-shadow:none;background:#f8faff;margin-bottom:17px"><div class="micro">Current plan</div><h3 style="margin:5px 0">{{ ucfirst($subscription?->plan_code ?? 'No active plan') }}</h3><p style="margin:0">Billing status: {{ ucfirst($subscription?->status ?? 'Not subscribed') }}</p></div>
        <a class="btn btn-accent" href="{{ route('saas.workspace.billing', $workspace->public_id) }}">{{ $activeSubscription ? 'Manage billing' : 'Explore subscription plans' }} →</a>
        <p class="micro mt">Product import and store publishing remain unavailable until integrations pass release QA.</p>
    </div>
</div>
<div class="card section">
    <div class="split"><div><h2 style="margin-bottom:5px">Recent products</h2><p style="margin-bottom:0">Products are private to your workspace.</p></div><a class="btn btn-sm" href="{{ route('saas.workspace.products', $workspace->public_id) }}">View products →</a></div>
    @if($recentProducts->isEmpty())<div class="empty mt"><h3>No products imported yet</h3><p>The CJ product import connector will appear here when integration testing is complete.</p></div>
    @else
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>SKU</th><th>Status</th></tr></thead><tbody>
    @foreach($recentProducts as $product)<tr><td>{{ $product->title }}</td><td>{{ $product->sku ?: '—' }}</td><td><span class="tag">{{ ucfirst($product->status) }}</span></td></tr>@endforeach
    </tbody></table></div>
    @endif
</div>
@endsection
