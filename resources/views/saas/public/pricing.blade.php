@extends('saas.layout')
@section('title', 'Monthly Pricing')
@section('content')
<div class="marketing-main" style="padding-top:67px">
    <div style="text-align:center;max-width:780px;margin:0 auto 40px"><span class="eyebrow">Transparent plans</span><h1 style="margin:15px 0">Simple monthly pricing</h1><p style="font-size:18px">Select the product and store capacity that fits your catalog. These are proposed launch prices; checkout opens only once the plans are configured and approved.</p></div>
    <div class="grid">
        @foreach($plans as $code => $plan)
        <div class="card plan-card {{ $code === 'growth' ? 'plan-featured' : '' }}">
            <div class="split"><h3>{{ $plan['name'] }}</h3>@if($code === 'growth')<span class="tag good">Popular</span>@endif</div>
            <div class="plan-price">{{ $plan['price_label'] }} <small>/ month</small></div>
            <p style="margin-bottom:0">A workspace plan for CJ → Botble catalog management.</p>
            <ul><li>{{ number_format($plan['product_limit']) }} products</li><li>{{ $plan['store_limit'] }} connected {{ $plan['store_limit'] === 1 ? 'store' : 'stores' }}</li><li>{{ $plan['seat_limit'] }} team seats</li><li>{{ $plan['sync_label'] }} (upcoming)</li></ul>
            @if(config('saas.registration_enabled'))<a class="btn {{ $code === 'growth' ? 'btn-accent' : '' }}" href="{{ route('saas.register') }}">Create your workspace →</a>
            @else<button class="btn" disabled aria-label="Registration for {{ $plan['name'] }} is not open">Opening after beta</button>@endif
        </div>
        @endforeach
    </div>
    <p class="micro" style="text-align:center;margin-top:25px">Payments and product synchronization are not available until the Stripe and connector integrations are approved. No card is charged from this pricing page.</p>
</div>
@endsection
