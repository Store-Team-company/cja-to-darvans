@extends(\Botble\Base\Facades\BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
@php
    $isPaired = $pairing?->status === 'paired';
    $appLink = str_starts_with($saasUrl, 'https://') ? rtrim($saasUrl, '/') . '/app' : null;
@endphp
<style>
.dp-connect {max-width:1200px;margin:0 auto;color:var(--bb-body-color,#1e293b)}
.dp-connect *{box-sizing:border-box}
.dp-connect .dp-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px}
.dp-connect h1{margin:0 0 7px;font-size:25px;font-weight:750;line-height:1.2}
.dp-connect h2{margin:0 0 9px;font-size:18px;font-weight:700}
.dp-connect p{margin:0 0 12px;line-height:1.55}
.dp-connect .dp-sub{color:var(--bb-secondary-color,#64748b);margin:0}
.dp-connect .dp-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(270px,1fr);gap:18px}
.dp-connect .dp-card{border:1px solid var(--bb-border-color,#dce3ed);background:var(--bb-card-bg,#fff);border-radius:12px;margin-bottom:18px;overflow:hidden}
.dp-connect .dp-card-header{padding:18px 20px;border-bottom:1px solid var(--bb-border-color,#e2e8f0);font-weight:700;font-size:16px}
.dp-connect .dp-card-body{padding:20px}
.dp-connect .dp-pill{display:inline-flex;align-items:center;gap:7px;border-radius:30px;padding:6px 12px;font-size:13px;font-weight:700;background:#fff3db;color:#965b04}
.dp-connect .dp-pill.on{background:#dff6e9;color:#146c43}
.dp-connect .dp-dot{display:inline-block;width:7px;height:7px;background:currentColor;border-radius:100%}
.dp-connect .dp-statrow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.dp-connect .dp-stat{padding:14px;border:1px solid var(--bb-border-color,#e2e8f0);border-radius:9px}
.dp-connect .dp-stat strong{display:block;font-size:22px;font-weight:750}
.dp-connect .dp-muted{font-size:13px;color:var(--bb-secondary-color,#64748b)}
.dp-connect .dp-kv{display:grid;grid-template-columns:160px minmax(0,1fr);gap:8px;padding:10px 0;border-bottom:1px solid var(--bb-border-color,#e2e8f0)}
.dp-connect .dp-kv:last-child{border-bottom:0}
.dp-connect .dp-value{overflow-wrap:anywhere}
.dp-connect .dp-formlabel{display:block;margin:15px 0 6px;font-size:14px;font-weight:650}
.dp-connect .dp-input{display:block;width:100%;max-width:100%;min-height:42px;border:1px solid #b7c4d4;border-radius:7px;padding:9px 11px;background:var(--bb-card-bg,#fff);color:inherit}
.dp-connect .dp-input:focus{outline:2px solid #2769c1;outline-offset:1px}
.dp-connect .dp-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.dp-connect .dp-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #1e3a68;background:#1e3a68;color:#fff;border-radius:7px;font-size:14px;font-weight:700;padding:10px 15px;text-decoration:none;cursor:pointer}
.dp-connect .dp-btn:hover{color:#fff;background:#182e50}
.dp-connect .dp-btn.outline{color:var(--bb-body-color,#1e293b);background:var(--bb-card-bg,#fff);border-color:#94a3b8}
.dp-connect .dp-btn.danger{color:#9e2525;background:transparent;border-color:#d9acac}
.dp-connect .dp-alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;border:1px solid #f2ce8b;background:#fff8e7;color:#754700}
.dp-connect .dp-alert.success{border-color:#adddc4;background:#ebfaf2;color:#126e45}
.dp-connect .dp-step{display:flex;gap:12px;margin-bottom:20px}
.dp-connect .dp-step:last-child{margin-bottom:0}
.dp-connect .dp-step-num{flex:0 0 29px;height:29px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:#e8eef9;color:#153c79;font-weight:700}
.dp-connect .dp-step p{margin:2px 0 0;font-size:13px;color:var(--bb-secondary-color,#64748b)}
.dp-connect .dp-mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;overflow-wrap:anywhere}
.dp-connect .dp-links{display:flex;gap:9px;flex-wrap:wrap}
.dp-connect .dp-links a{font-weight:650}
@media(max-width:930px){.dp-connect .dp-grid{grid-template-columns:1fr}}
@media(max-width:560px){.dp-connect .dp-card-body{padding:16px}.dp-connect .dp-statrow{grid-template-columns:1fr}.dp-connect .dp-kv{grid-template-columns:1fr;gap:2px}}
</style>
<div class="dp-connect">
    <div class="dp-hero">
        <div>
            <h1>Data Pilot <span class="dp-muted">Connector v0.3.1</span></h1>
            <p class="dp-sub">Connect this Botble store securely and review incoming CJ products before catalog import.</p>
        </div>
        <span class="dp-pill {{ $isPaired ? 'on' : '' }}" role="status"><span class="dp-dot"></span>{{ $isPaired ? 'Paired' : 'Not connected' }}</span>
    </div>

    @if(session('status'))
        <div class="dp-alert success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="dp-alert" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="dp-statrow" style="margin-bottom:18px">
        <div class="dp-stat"><span class="dp-muted">Pairing</span><strong>{{ $isPaired ? 'Active' : 'Pending' }}</strong></div>
        <div class="dp-stat"><span class="dp-muted">Incoming drafts</span><strong>{{ $inboxCount }}</strong></div>
        <div class="dp-stat"><span class="dp-muted">Native catalog import</span><strong>{{ config('datapilot-connector.catalog_import_enabled', false) ? 'Enabled' : 'Off' }}</strong></div>
    </div>

    <div class="dp-grid">
        <div>
            <section class="dp-card">
                <div class="dp-card-header">Connection settings</div>
                <div class="dp-card-body">
                    <div class="dp-kv"><span class="dp-muted">Data Pilot application</span><span class="dp-value dp-mono">{{ $saasUrl ?: 'Not configured' }}</span></div>
                    <div class="dp-kv"><span class="dp-muted">Botble store URL</span><span class="dp-value dp-mono">{{ config('app.url') }}</span></div>
                    <div class="dp-kv"><span class="dp-muted">Workspace ID</span><span class="dp-value dp-mono">{{ $isPaired ? $pairing->workspace_public_id : 'Not paired' }}</span></div>
                    <div class="dp-kv"><span class="dp-muted">Store ID</span><span class="dp-value dp-mono">{{ $isPaired ? $pairing->store_public_id : 'Not paired' }}</span></div>
                    <div class="dp-kv"><span class="dp-muted">Paired at</span><span class="dp-value">{{ $isPaired ? $pairing->paired_at?->format('Y-m-d H:i T') : '—' }}</span></div>
                    <div class="dp-actions">
                        <a class="dp-btn outline" href="{{ route('datapilot.connector.incoming') }}">Review incoming products</a>
                    </div>
                </div>
            </section>

            @if($isPaired)
                <section class="dp-card">
                    <div class="dp-card-header">Secure connection established</div>
                    <div class="dp-card-body">
                        <div class="dp-alert success">The store has a saved encrypted pairing secret. This does not mean product transfers have been enabled or tested.</div>
                        <p>For staging QA, enable inbound signed drafts separately after validating the Data Pilot sender. Catalog imports also require an administrator to approve each draft.</p>
                        <form method="post" action="{{ route('datapilot.connector.revoke') }}" onsubmit="return confirm('Revoke the local pairing? The Data Pilot side must be revoked separately.');">
                            @csrf
                            <button type="submit" class="dp-btn danger">Revoke local connection</button>
                        </form>
                    </div>
                </section>
            @else
                <section class="dp-card">
                    <div class="dp-card-header">Complete secure pairing</div>
                    <div class="dp-card-body">
                        <p>Generate the credentials in the <strong>Data Pilot customer workspace</strong>, then enter them below. They are not your Botble login or database credentials.</p>
                        <form method="post" action="{{ route('datapilot.connector.pair') }}" autocomplete="off">
                            @csrf
                            <label class="dp-formlabel" for="dp-workspace">Workspace ID</label>
                            <input class="dp-input dp-mono" id="dp-workspace" type="text" name="workspace_id" required value="{{ old('workspace_id') }}" placeholder="Workspace UUID" maxlength="36">
                            <label class="dp-formlabel" for="dp-store">Store ID</label>
                            <input class="dp-input dp-mono" id="dp-store" type="text" name="store_id" required value="{{ old('store_id') }}" placeholder="Registered store UUID" maxlength="36">
                            <label class="dp-formlabel" for="dp-code">One-time pairing code</label>
                            <input class="dp-input dp-mono" id="dp-code" type="password" name="pairing_code" required maxlength="64" minlength="64" autocomplete="one-time-code" placeholder="64-character code, expires in 10 minutes">
                            <p class="dp-muted" style="margin-top:8px">The secret is sent only to your trusted HTTPS Data Pilot endpoint. It is never displayed after pairing.</p>
                            <div class="dp-actions">
                                <button class="dp-btn" type="submit">Connect Botble store</button>
                                @if($appLink)<a class="dp-btn outline" href="{{ $appLink }}" target="_blank" rel="noopener noreferrer">Open Data Pilot workspace ↗</a>@endif
                            </div>
                        </form>
                    </div>
                </section>
            @endif
        </div>

        <div>
            <section class="dp-card">
                <div class="dp-card-header">How to connect your own store</div>
                <div class="dp-card-body">
                    <div class="dp-step"><span class="dp-step-num">1</span><div><strong>Sign in to Data Pilot</strong><p>Use the /app customer portal. The legacy /settings Connected Store page is a different single-store integration.</p></div></div>
                    <div class="dp-step"><span class="dp-step-num">2</span><div><strong>Register this Botble URL</strong><p>Add <span class="dp-mono">{{ config('app.url') }}</span> under Workspace → Connections. Choose Botble.</p></div></div>
                    <div class="dp-step"><span class="dp-step-num">3</span><div><strong>Generate a pairing code</strong><p>With verified workspace access and an authorized entitlement, generate a fresh code. Copy Workspace ID, Store ID and code.</p></div></div>
                    <div class="dp-step"><span class="dp-step-num">4</span><div><strong>Complete pairing here</strong><p>Paste all three values above within 10 minutes. If rejected, check that SaaS pairing is enabled and the registered URL matches exactly.</p></div></div>
                    @if($appLink)
                        <div class="dp-actions"><a class="dp-btn outline" href="{{ $appLink }}" target="_blank" rel="noopener noreferrer">Go to Data Pilot ↗</a></div>
                    @endif
                </div>
            </section>
            <section class="dp-card">
                <div class="dp-card-header">Transfer readiness</div>
                <div class="dp-card-body">
                    <div class="dp-kv"><span class="dp-muted">Signed draft inbox</span><strong>{{ config('datapilot-connector.inbound_enabled', false) ? 'Enabled for staging' : 'Disabled' }}</strong></div>
                    <div class="dp-kv"><span class="dp-muted">Native catalog import</span><strong>{{ config('datapilot-connector.catalog_import_enabled', false) ? 'Enabled for staging' : 'Disabled' }}</strong></div>
                    <div class="dp-alert" style="margin-top:15px;margin-bottom:0">Product transfer will not work until the SaaS pairing endpoint and entitlement are active. Leave catalog writes disabled until actual Botble eCommerce version compatibility and security checks pass.</div>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
