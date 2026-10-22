@extends('saas.layout')
@section('title', 'CJ to Botble Product Management')
@section('content')
<div class="marketing-main">
    <section style="padding:92px 0 75px;max-width:950px">
        <span class="tag good">Built for Botble merchants</span>
        <h1 style="font-size:clamp(39px,6vw,72px);max-width:940px;margin:20px 0">From CJ products to your store. <span style="color:#4869e8">One clear workspace.</span></h1>
        <p style="font-size:19px;max-width:670px;line-height:1.7">Manage CJ product imports, preview costs and variations, and organize what gets published to your own Botble store. Data Pilot is building a simpler way to operate your product catalog.</p>
        <div class="flex" style="margin-top:29px">
            <a class="btn btn-accent" href="{{ route('saas.pricing') }}">Explore monthly plans →</a>
            @if(config('saas.registration_enabled'))<a class="btn" href="{{ route('saas.register') }}">Create an account</a>
            @else<span class="tag warn">Registration opens after private beta QA</span>@endif
        </div>
    </section>
    <section class="grid" style="margin-bottom:50px">
        <div class="card"><span class="eyebrow">01 · Connect</span><h2 style="margin-top:13px">Your accounts, your control</h2><p>Each merchant has an isolated workspace, and will connect their own CJ credentials and Botble store using the Data Pilot Connector.</p></div>
        <div class="card"><span class="eyebrow">02 · Prepare</span><h2 style="margin-top:13px">Review before publishing</h2><p>Organize imported products, pricing decisions, category mappings and product readiness from one place.</p></div>
        <div class="card"><span class="eyebrow">03 · Synchronize</span><h2 style="margin-top:13px">Understand every update</h2><p>Planned sync history, status visibility and clear error messages make managing product catalogs less confusing.</p></div>
    </section>
    <section class="card" style="padding:44px;display:flex;justify-content:space-between;align-items:center;gap:25px;flex-wrap:wrap;background:#101b36;color:white">
        <div style="max-width:620px"><span class="eyebrow" style="color:#a8bfff">Private beta</span><h2 style="font-size:31px;margin:8px 0">Start with Botble. Grow with your business.</h2><p style="color:#b8c7dd;margin-bottom:0">Shopify, WooCommerce and additional sources are future roadmap items, not available integrations today.</p></div>
        <a href="{{ route('saas.pricing') }}" class="btn" style="flex-shrink:0">View plans →</a>
    </section>
</div>
@endsection
