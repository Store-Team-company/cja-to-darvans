@extends(\Botble\Base\Facades\BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
<style>
.dp-inbox{max-width:1200px;margin:0 auto;color:var(--bb-body-color,#1e293b)}
.dp-inbox *{box-sizing:border-box}
.dp-inbox .dp-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:20px}
.dp-inbox h1{font-size:25px;font-weight:740;margin:0 0 8px}
.dp-inbox h2{font-size:17px;font-weight:710;margin:0 0 8px}
.dp-inbox .dp-muted{color:var(--bb-secondary-color,#64748b);font-size:13px}
.dp-inbox .dp-card{background:var(--bb-card-bg,#fff);border:1px solid var(--bb-border-color,#dce3ed);padding:20px;border-radius:11px;margin-bottom:15px}
.dp-inbox .dp-btn{display:inline-flex;align-items:center;justify-content:center;background:#1e3a68;color:#fff;border:1px solid #1e3a68;border-radius:7px;padding:10px 14px;font-size:14px;font-weight:700;text-decoration:none}
.dp-inbox .dp-btn.secondary{color:inherit;background:transparent;border-color:#94a3b8}
.dp-inbox .dp-btn:disabled{opacity:.55;cursor:not-allowed}
.dp-inbox .dp-pill{padding:4px 9px;border-radius:25px;background:#eaf0fa;font-weight:700;font-size:12px;white-space:nowrap}
.dp-inbox .dp-alert{margin:15px 0;border-radius:8px;padding:12px;border:1px solid #ebce98;background:#fff8e8;color:#7a5008}
.dp-inbox .dp-alert.success{background:#ebfbf1;border-color:#b8dec7;color:#1a6845}
.dp-inbox .dp-summary{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
.dp-inbox .dp-meta{display:flex;flex-wrap:wrap;gap:18px;margin:12px 0 15px;font-size:13px}
.dp-inbox .dp-meta>span{overflow-wrap:anywhere}
.dp-inbox .dp-actions{margin-top:17px;display:flex;gap:10px;flex-wrap:wrap}
.dp-inbox pre{background:var(--bb-bg-surface-secondary,#f8fafc);padding:13px;border:1px solid var(--bb-border-color,#dbe4f0);border-radius:8px;white-space:pre-wrap;overflow-wrap:anywhere;max-height:300px;overflow:auto}
.dp-inbox summary{cursor:pointer;font-weight:650}
.dp-inbox code{overflow-wrap:anywhere}
@media(max-width:600px){.dp-inbox .dp-card{padding:14px}}
</style>
<div class="dp-inbox">
    <div class="dp-heading">
        <div>
            <h1>Incoming CJ products <span class="dp-muted">Data Pilot Connector v0.3.3</span></h1>
            <p class="dp-muted">Signed products are staged first. Native catalog changes require explicit Botble admin review and approval.</p>
        </div>
        <a class="dp-btn secondary" href="{{ route('datapilot.connector.settings') }}">← Connection settings</a>
    </div>

    @if(session('status'))<div role="status" class="dp-alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="dp-alert">{{ $errors->first() }}</div>@endif
    @unless(config('datapilot-connector.catalog_import_enabled', false))
        <div class="dp-alert">Catalog writes are disabled until this store's actual eCommerce version passes compatibility QA. Review of inbound drafts remains available.</div>
    @endunless

    @forelse($drafts as $draft)
        @php
            $payload = $draft->payload ?? [];
            $mapping = $mappings->get($draft->pairing_id.'|'.$draft->source_product_id);
            $variants = $payload['variants'] ?? [];
        @endphp
        <section class="dp-card">
            <div class="dp-summary">
                <div><h2>{{ $draft->title }}</h2><div class="dp-muted">CJ source: <code>{{ $draft->source_product_id }}</code></div></div>
                <span class="dp-pill">{{ ucwords(str_replace('_', ' ', $draft->status)) }}</span>
            </div>
            <div class="dp-meta">
                <span><strong>SKU:</strong> {{ $draft->sku ?: 'Not supplied' }}</span>
                <span><strong>Variations:</strong> {{ count($variants) }}</span>
                <span><strong>Attempts:</strong> {{ $draft->import_attempts ?? 0 }}</span>
                <span><strong>Received:</strong> {{ $draft->created_at?->format('Y-m-d H:i') }}</span>
                @if($mapping)<span><strong>Botble product ID:</strong> {{ $mapping->botble_product_id }}</span>@endif
            </div>
            @if(($payload['schema_version'] ?? null) === 2)
                <div class="dp-alert">Complete {{ count($variants) }}-variant protocol-v2 draft received for review only. Original supplier option names and all SKU mappings are preserved. Native import is separately blocked pending verified media and variation compatibility.</div>
            @endif
            <p class="dp-muted">Workspace: <code>{{ $draft->pairing?->workspace_public_id ?? 'Unknown' }}</code> · Store: <code>{{ $draft->pairing?->store_public_id ?? 'Unknown' }}</code></p>

            @if($draft->last_import_error)
                <div class="dp-alert" role="alert">Last import error: {{ $draft->last_import_error }}</div>
            @endif
            @if(!empty($payload['images']) || !empty($payload['image']))
                <div class="dp-alert">Media import has not been approved for this store version. Images will never be silently skipped.</div>
            @endif

            <details>
                <summary>Review incoming product data</summary>
                <pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
            </details>
            <form class="dp-actions" method="post" action="{{ route('datapilot.connector.incoming.approve', $draft) }}" onsubmit="return confirm('Create or update this native Botble draft product?');">
                @csrf
                <button class="dp-btn" type="submit" @disabled(($payload['schema_version'] ?? null) === 2 || !config('datapilot-connector.catalog_import_enabled', false) || $draft->pairing?->status !== 'paired')>
                    {{ $draft->status === 'failed' ? 'Retry native draft import' : 'Approve draft import' }}
                </button>
            </form>
        </section>
    @empty
        <section class="dp-card"><h2>No incoming products yet</h2><p class="dp-muted">Connect the site from Connection settings, then send a signed CJ draft from an authorized Data Pilot workspace.</p><a class="dp-btn secondary" href="{{ route('datapilot.connector.settings') }}">Connection settings</a></section>
    @endforelse
    <div>{{ $drafts->links() }}</div>
</div>
@endsection
