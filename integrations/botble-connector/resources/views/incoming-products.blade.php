<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Incoming Products | Data Pilot</title>
<style>
body{margin:0;background:#f4f7fb;color:#172238;font:15px/1.55 system-ui,Arial,sans-serif}
main{max-width:1080px;margin:35px auto;padding:0 20px}
nav{display:flex;gap:14px;margin-bottom:20px}nav a{color:#1657a4}
section{border:1px solid #dde5f0;border-radius:12px;background:#fff;margin:16px 0;padding:19px}
h1{font-size:27px;margin:0 0 5px}h2{font-size:19px;margin:0 0 8px}
small,.muted{color:#596579}code{overflow-wrap:anywhere}
button{background:#17335d;color:#fff;border:0;border-radius:7px;padding:10px 15px;cursor:pointer;font-weight:650}
button:disabled{opacity:.5;cursor:not-allowed}
pre{overflow:auto;background:#f8fafc;border:1px solid #dce6f1;padding:12px;border-radius:8px;max-height:290px;white-space:pre-wrap;overflow-wrap:anywhere}
.badge{display:inline-block;border-radius:6px;background:#e9f1fb;padding:2px 7px}
.alert{border:1px solid #e4bfa6;background:#fff6e9;padding:12px;border-radius:8px;margin:16px 0}
.success{background:#e7f8ed;border:1px solid #b1dec3;padding:12px;border-radius:8px;margin:16px 0}
.pager{margin:20px 0}
@media(max-width:600px){main{padding:0 12px;margin:14px auto}section{padding:13px}nav{flex-wrap:wrap}}
</style>
</head>
<body>
<main>
<nav><a href="{{ route('datapilot.connector.settings') }}">Connection Settings</a><a href="{{ route('datapilot.connector.incoming') }}" aria-current="page">Incoming Products</a></nav>
<h1>Incoming Products</h1>
<p class="muted">Review signed CJ product drafts before saving into native Botble eCommerce. Nothing is published automatically.</p>
@if(session('status'))<div class="success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert" role="alert">{{ $errors->first() }}</div>@endif
@if(!config('datapilot-connector.catalog_import_enabled', false))
<div class="alert">Native imports are disabled. Enable only after confirming this Botble eCommerce schema on a disposable staging store.</div>
@endif
@forelse($drafts as $draft)
@php
$payload = $draft->payload ?? [];
$mapping = $mappings->get($draft->pairing_id.'|'.$draft->source_product_id);
$variants = $payload['variants'] ?? [];
@endphp
<section>
<h2>{{ $draft->title }} <span class="badge">{{ $draft->status }}</span></h2>
<p><strong>CJ source ID:</strong> <code>{{ $draft->source_product_id }}</code>
&nbsp; <strong>SKU:</strong> {{ $draft->sku ?: 'Missing' }}
&nbsp; <strong>Variations:</strong> {{ count($variants) }}
&nbsp; <strong>Received:</strong> {{ $draft->created_at?->format('Y-m-d H:i') }}
&nbsp; <strong>Attempts:</strong> {{ $draft->import_attempts ?? 0 }}</p>
@if($draft->last_import_error)<p role="alert" class="alert"><strong>Last failure:</strong> {{ $draft->last_import_error }}<br><small>Retry after correcting this issue.</small></p>@endif
<p><strong>Workspace:</strong> {{ $draft->pairing?->workspace_public_id ?? 'Unknown' }}
&nbsp; <strong>Store:</strong> {{ $draft->pairing?->store_public_id ?? 'Unknown' }}</p>
@if($mapping)
<p><strong>Native Botble Product ID:</strong> {{ $mapping->botble_product_id }}</p>
@endif
<details><summary>Review full received product payload</summary>
<pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
</details>
@if(!empty($payload['images']) || !empty($payload['image']))
<div class="alert">Images are included but secure media import has not passed compatibility QA. Approval will not silently discard them.</div>
@endif
<form method="post" action="{{ route('datapilot.connector.incoming.approve', $draft) }}" style="margin-top:15px">
@csrf
<button type="submit" @disabled(!config('datapilot-connector.catalog_import_enabled', false) || ($draft->pairing?->status !== 'paired')) onclick="return confirm('Create or update the native Botble draft product? Existing published catalog items will not be published automatically.');">{{ $draft->status === 'failed' ? 'Retry native draft import' : 'Approve native draft import' }}</button>
</form>
</section>
@empty
<section><h2>No incoming products</h2><p>Pair this store, enable signed inbound drafts in staging and send a signed product payload from Data Pilot.</p></section>
@endforelse
<div class="pager">{{ $drafts->links() }}</div>
</main>
</body>
</html>
