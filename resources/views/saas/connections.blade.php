@extends('saas.layout')
@section('title', 'Connections')
@section('content')
<div class="page-title"><span class="eyebrow">Integrations</span><h1>Connections</h1><p>Each store and CJ account is owned by this workspace. Credentials are never displayed here.</p></div>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
    <div class="card">
        <div class="split"><h2>CJ Dropshipping</h2><span class="tag {{ $cjConnection?->status === 'connected' ? 'good' : 'off' }}">{{ ucfirst($cjConnection?->status ?? 'Not connected') }}</span></div>
        <p>Use your own CJ developer account and API credentials. Connection management is being built with per-account quotas and secure token storage.</p>
        <div class="alert">CJ authorization and product import are not available in this SaaS release. Do not enter API keys into the legacy admin connection screen for customer workspaces.</div>
    </div>
    <div class="card">
        <div class="split"><h2>Botble stores</h2><span class="tag">{{ $stores->count() }} saved</span></div>
        <p>Install the separate Data Pilot Botble Connector on your own store. Secure pairing, store verification, and draft publishing are upcoming.</p>
        @forelse($stores as $store)
            <div class="card" style="box-shadow:none;padding:13px;margin:8px 0">
                <strong>{{ $store->name }}</strong><div class="micro">{{ $store->base_url }}</div>
                <span class="tag {{ $store->status === 'connected' ? 'good' : 'off' }}">{{ ucfirst($store->status) }}</span>
            </div>
        @empty<div class="empty">No Botble stores paired to your workspace.</div>@endforelse
    </div>
</div>
@endsection
