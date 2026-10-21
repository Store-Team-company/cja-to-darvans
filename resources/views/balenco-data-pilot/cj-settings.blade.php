@extends('balenco-data-pilot.layout')

@section('content')
<div class="card">
    <h2>CJ API Settings</h2>
    <p>Use your CJ API Key to let Data Pilot pull product details, variants, images, stock, and prices directly from CJ. CJ access tokens are stored only on this server.</p>
    <form method="POST" action="{{ route('bdp.cj.settings.save') }}">
        @csrf
        <label>CJ API Key</label>
        <input type="password" name="api_key" value="{{ old('api_key', $token->api_key) }}" placeholder="CJxxxx@api@xxxxxxxx">
        <div style="margin-top:12px"><button class="btn orange" type="submit">Save API Key</button></div>
    </form>
</div>

<div class="card">
    <h3>Token Status</h3>
    <table>
        <tr><th>Open ID</th><td>{{ $token->open_id ?: '-' }}</td></tr>
        <tr><th>Access Token</th><td>{{ $token->access_token ? substr($token->access_token,0,8).'...'.substr($token->access_token,-6) : '-' }}</td></tr>
        <tr><th>Access Token Expires</th><td>{{ optional($token->access_token_expires_at)->format('Y-m-d H:i') ?: '-' }}</td></tr>
        <tr><th>Refresh Token Expires</th><td>{{ optional($token->refresh_token_expires_at)->format('Y-m-d H:i') ?: '-' }}</td></tr>
        <tr><th>Last Tested</th><td>{{ optional($token->last_tested_at)->format('Y-m-d H:i') ?: '-' }}</td></tr>
    </table>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:15px">
        <form method="POST" action="{{ route('bdp.cj.token') }}">@csrf<button class="btn orange" type="submit">Get Access Token</button></form>
        <form method="POST" action="{{ route('bdp.cj.token.refresh') }}">@csrf<button class="btn" type="submit">Refresh Token</button></form>
        <form method="POST" action="{{ route('bdp.cj.test') }}">@csrf<button class="btn light" type="submit">Test Connection</button></form>
    </div>
</div>

<div class="card">
    <h3>Next Step</h3>
    <p>After the connection test passes, go to <a href="{{ route('bdp.cj.import') }}">CJ API Import</a>, search products, then import selected products into Data Pilot.</p>
</div>
@endsection
