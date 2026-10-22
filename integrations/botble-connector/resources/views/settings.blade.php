<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Data Pilot Connector | Botble</title>
<style>
body{margin:0;background:#f4f7fb;color:#172238;font:15px/1.6 system-ui,Arial,sans-serif}
main{max-width:760px;margin:40px auto;padding:0 18px}.card{padding:26px;border-radius:14px;border:1px solid #e0e7f0;background:white;box-shadow:0 10px 25px rgba(12,24,43,.04)}h1{margin:0 0 7px;font-size:26px}h2{font-size:19px;margin:0 0 8px}.sub{color:#697789}.pill{padding:6px 10px;border-radius:99px;background:#eaf1fc;color:#1f459d;font-weight:750;font-size:12px}
label{display:block;font-weight:730;margin:12px 0 5px}input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:11px;font-size:15px}.btn{border:0;border-radius:8px;background:#162548;color:#fff;padding:12px 16px;font-weight:750;cursor:pointer}.btn-alt{background:#fff;color:#a52a23;border:1px solid #e7b9b6}.warn{background:#fff5e8;border:1px solid #f4d8ad;padding:14px;border-radius:8px;color:#805025;margin:18px 0}.ok{background:#e9f8ed;padding:12px;border-radius:8px;color:#18663f}.block{margin:16px 0}
</style></head><body><main><div class="card">
<h1>Data Pilot Connector <span class="pill">v0.3.0</span></h1>
<p class="sub">Connect this Botble store to your Data Pilot SaaS workspace. Pairing is initiated by a Botble administrator; your database password is never shared.</p>
@if(session('status'))<div class="ok" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warn" role="alert">{{ $errors->first() }}</div>@endif
<div class="block"><a href="{{ route('datapilot.connector.incoming') }}" style="display:inline-block;margin-bottom:12px;color:#17335d;font-weight:700">Review incoming products →</a></div>
<div class="block">
<strong>Data Pilot server:</strong> {{ $saasUrl ?: 'Not configured' }}<br>
<strong>This Botble site:</strong> {{ config('app.url') }}<br>
<strong>Inbound drafts awaiting review:</strong> {{ $inboxCount }}<br>
<strong>Native catalog import:</strong> {{ config('datapilot-connector.catalog_import_enabled', false) ? 'Enabled (STAGING ONLY)' : 'Disabled (safe default)' }}
</div>
@if($pairing?->status === 'paired')
<div class="ok"><h2>Paired successfully</h2>
<p>Workspace ID: {{ $pairing->workspace_public_id }}<br>
Store ID: {{ $pairing->store_public_id }}</p>
<p>Incoming product transfer is disabled until independently tested against this Botble eCommerce version.</p>
</div>
<form method="post" action="{{ route('datapilot.connector.revoke') }}" style="margin-top:20px">@csrf
<button class="btn btn-alt" type="submit">Revoke this connection</button>
</form>
@else
<h2>Connect your store</h2>
<p>In your Data Pilot workspace, register this exact HTTPS store domain and generate a pairing code. Paste the workspace ID, store ID and code below.</p>
<form method="post" action="{{ route('datapilot.connector.pair') }}">
@csrf
<label for="workspace_id">Workspace ID</label><input id="workspace_id" name="workspace_id" required value="{{ old('workspace_id') }}">
<label for="store_id">Store ID</label><input id="store_id" name="store_id" required value="{{ old('store_id') }}">
<label for="pairing_code">One-time pairing code</label><input id="pairing_code" type="password" name="pairing_code" required minlength="64" maxlength="64" autocomplete="off">
<button class="btn" type="submit" style="margin-top:20px">Pair this Botble store</button>
</form>
@endif
<div class="warn"><strong>Safety:</strong> Inbound transfer only stages encrypted drafts. Native catalog writes require explicit administrator approval and DATAPILOT_CATALOG_IMPORT_ENABLED=true. Never enable before real Botble compatibility QA; the connector does not auto-publish. Configure <code>DATAPILOT_SAAS_URL</code> on the Botble server before pairing, and keep <code>DATAPILOT_INBOUND_DRAFTS_ENABLED=false</code> until QA.</div>
</div></main></body></html>
