<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $workspace->name }} | Data Pilot</title>
    <style>body{font:16px/1.5 system-ui,sans-serif;max-width:900px;margin:50px auto;padding:0 20px;color:#172038}h1{margin-bottom:0}p{color:#526078}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:25px 0}.card{border:1px solid #d9e0ea;border-radius:12px;padding:18px}.card b{font-size:28px;display:block}nav{display:flex;justify-content:space-between}</style>
</head>
<body>
<nav><strong>Data Pilot · Customer workspace</strong><span>{{ $role }}</span></nav>
<h1>{{ $workspace->name }}</h1>
<p>This is the isolated workspace foundation. Product imports, Botble publishing and subscriptions will be enabled only after connector and Stripe security QA.</p>
<div class="cards">
    <div class="card"><span>Products</span><b>{{ $productCount }}</b></div>
    <div class="card"><span>Botble stores</span><b>{{ $storeCount }}</b></div>
    <div class="card"><span>CJ API</span><b style="font-size:18px">{{ $cjConnected ? 'Connected' : 'Not connected' }}</b></div>
</div>
<p><strong>Subscription status:</strong> Pending integration. No charges or imports can be performed from this preview.</p>
<form method="post" action="{{ route('saas.logout') }}">@csrf<button type="submit">Sign out</button></form>
</body>
</html>
