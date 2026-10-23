@extends('saas.layout')
@section('title', 'Connections')
@section('content')
@php
    $canConnectCj = $role === 'owner' && ($activeSubscription
        || app(\App\Services\Saas\SaasCjPilot::class)->allows($workspace));
    // Only the specifically pinned internal staging workspace may see the
    // limited no-billing Botble registration form.
    $pilot = app(\App\Services\Saas\SaasFirstPartyPilot::class);
    $canRegisterPilotStore = $pilot->allowsStoreRegistration($workspace, 'https://sandbox.daystart.pk');
@endphp
<div class="page-title"><span class="eyebrow">Your integrations</span><h1>Connections</h1><p>Configure your own CJ account and Botble destination stores. All saved connections belong exclusively to this workspace.</p></div>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
    <div class="card">
        <div class="split"><h2>CJ Dropshipping</h2><span class="tag {{ $cjConnection?->status === 'connected' ? 'good' : 'off' }}">{{ str_replace('_', ' ', ucfirst($cjConnection?->status ?? 'Not connected')) }}</span></div>
        <p>Connect your own CJ Dropshipping account using its API key. Data Pilot encrypts the credentials and verifies them directly with the official CJ API.</p>
        @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
        @error('cj')<p class="alert" role="alert">{{ $message }}</p>@enderror
        @if($cjConnection)
            <p class="micro">Status:
                <strong>{{ str_replace('_', ' ', ucfirst($cjConnection->status)) }}</strong>
            </p>
            @if($cjConnection->last_verified_at)
                <p class="micro">Last verified: {{ $cjConnection->last_verified_at->format('Y-m-d H:i T') }}</p>
            @endif
            @if($cjConnection->status === 'failed')
                <p class="alert">CJ verification failed. Check your API key and authorization or retry after the cooldown.</p>
            @endif
        @endif
        @if($canConnectCj)
            <form method="post" action="{{ route('saas.workspace.connections.cj.save', $workspace->public_id) }}" autocomplete="off">@csrf
                <div class="form-field">
                    <label for="cj_api_key">{{ $cjConnection && $cjConnection->api_key ? 'Replace CJ API key' : 'CJ API key' }}</label>
                    <input type="password" id="cj_api_key" name="api_key" autocomplete="new-password" required minlength="16" maxlength="200" placeholder="Paste CJ API key">
                    @error('api_key')<span class="error" role="alert">{{ $message }}</span>@enderror
                </div>
                <button class="btn btn-primary" type="submit">Save encrypted key</button>
            </form>
            @if($cjConnection && $cjConnection->api_key)
                <form method="post" action="{{ route('saas.workspace.connections.cj.verify', $workspace->public_id) }}" style="margin-top:12px">@csrf
                    <button class="btn btn-primary" type="submit">Test connection</button>
                </form>
                <form method="post" action="{{ route('saas.workspace.connections.cj.remove', $workspace->public_id) }}" style="margin-top:12px">@csrf @method('DELETE')
                    <button class="btn btn-sm" type="submit">Disconnect CJ</button>
                </form>
            @endif
        @elseif($role !== 'owner')
            <p class="micro">Only the workspace owner can manage CJ credentials.</p>
        @else
            <p class="alert">Activate a monthly subscription before connecting CJ. A separately authorized internal CJ pilot may be used on one approved staging workspace.</p>
        @endif
        <p class="micro mt">CJ authentication does not import products or enable catalog publishing.</p>
    </div>
    <div class="card">
        <div class="split"><h2>Botble stores</h2><span class="tag">{{ $stores->count() }} saved</span></div>
        <p>Register the public HTTPS domain of a Botble store you own. Install the Data Pilot Botble Connector plugin on your store, then pair it with a single-use code.</p>
        @if($role === 'owner' && ($activeSubscription || $canRegisterPilotStore))
        <form method="post" action="{{ route('saas.workspace.connections.botble.add', $workspace->public_id) }}">@csrf
            <div class="form-field"><label for="store_name">Store name</label><input id="store_name" name="name" placeholder="My Botble Shop" maxlength="150" required value="{{ old('name') }}">@error('name')<span class="error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="store_url">Store URL</label><input id="store_url" name="base_url" type="url" placeholder="https://shop.example.com" maxlength="255" required value="{{ old('base_url', !$activeSubscription && $canRegisterPilotStore ? 'https://sandbox.daystart.pk' : '') }}" @if(!$activeSubscription && $canRegisterPilotStore) readonly @endif>@error('base_url')<span class="error" role="alert">{{ $message }}</span>@enderror</div>
            <button type="submit" class="btn btn-primary">Register store for pairing</button>
        </form>
        @elseif($role !== 'owner')<p class="micro">Only workspace owners can register Botble stores.</p>
        @else<p class="alert">Activate a plan before registering a store. The internal first-party pilot, if authorized server-side, is restricted to sandbox.daystart.pk.</p>@endif
        @if(!$activeSubscription && $canRegisterPilotStore)
        <p class="micro">Internal pilot: you may register only <strong>https://sandbox.daystart.pk</strong>. After registration, an administrator must pin the displayed Store ID in Data Pilot's private server configuration before you generate a pairing code.</p>
        @endif
        <div style="margin-top:20px">
        @if(session('pairing_code'))
        <div class="alert ok">
            <strong>One-time Botble pairing code (expires in 10 minutes)</strong>
            <p class="micro">Store ID: {{ session('pairing_store') }}</p>
            <p style="overflow-wrap:anywhere;font-family:monospace;font-size:14px;color:#123246">{{ session('pairing_code') }}</p>
            <p class="micro">This code is displayed once. Paste it into the Data Pilot Connector settings in the Botble admin panel.</p>
        </div>
        @endif
        @forelse($stores as $store)
            <div class="card" style="box-shadow:none;padding:13px;margin:8px 0">
                <div class="split"><strong>{{ $store->name }}</strong><span class="tag {{ $store->status === 'connected' ? 'good' : 'off' }}">{{ str_replace('_', ' ', ucfirst($store->status)) }}</span></div>
                <div class="micro">{{ $store->base_url }}</div>
                <div class="micro">Store ID: {{ $store->public_id }} · Workspace ID: {{ $workspace->public_id }}</div>
                @if($role === 'owner')
                    @if($store->status === 'connected')
                    <form method="post" action="{{ route('saas.workspace.connections.botble.revoke', ['workspace' => $workspace->public_id, 'store' => $store->id]) }}" style="margin-top:8px">@csrf<button class="btn btn-sm" type="submit">Revoke pairing</button></form>
                    @elseif($activeSubscription || $pilot->allowsPairing($workspace, $store))
                    <form method="post" action="{{ route('saas.workspace.connections.botble.pair-code', ['workspace' => $workspace->public_id, 'store' => $store->id]) }}" style="margin-top:8px">@csrf<button class="btn btn-sm" type="submit">Generate one-time pairing code</button></form>
                    @endif
                    @if($store->status !== 'connected')
                    <form method="post" action="{{ route('saas.workspace.connections.botble.remove', ['workspace' => $workspace->public_id, 'store' => $store->id]) }}" style="margin-top:8px">@csrf @method('DELETE')<button class="btn btn-sm" type="submit">Remove unpaired store</button></form>
                    @endif
                @endif
            </div>
        @empty<div class="empty">No Botble stores registered yet.</div>@endforelse
        </div>
        <p class="micro mt">Only an authorized Botble administrator should enter the pairing code. A paid plan or narrowly configured first-party staging pilot is required. Product publishing remains disabled.</p>
    </div>
</div>
@endsection
