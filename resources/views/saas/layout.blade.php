<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101b36">
    <title>@yield('title', config('saas.public_name')) · {{ config('saas.public_name') }}</title>
    <style>
        :root{--ink:#12213c;--sub:#67758c;--line:#e5eaf2;--bg:#f7f9fc;--navy:#101b36;--blue:#3b62e9;--orange:#ff713d;--good:#16815b;--radius:15px;--shadow:0 12px 30px rgba(16,27,54,.05)}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}a{color:inherit;text-decoration:none}button,input,select{font:inherit}button{cursor:pointer}h1,h2,h3,h4,p{margin-top:0}h1{letter-spacing:-.045em;line-height:1.12;font-size:clamp(30px,4vw,50px)}h2{letter-spacing:-.035em;line-height:1.2}p{color:var(--sub)}:focus-visible{outline:3px solid #f59e0b;outline-offset:3px}
        .brand{display:inline-flex;align-items:center;gap:10px;font-size:20px;font-weight:850;letter-spacing:-.05em}.brand-mark{background:linear-gradient(135deg,#1b3473,#466afa);color:#fff;border-radius:11px;display:grid;place-items:center;width:37px;height:37px;font-weight:900;font-size:19px}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:10px;border:1px solid #d9e0ee;padding:11px 17px;font-weight:740;background:white;color:var(--ink);white-space:normal;text-align:center}.btn-primary{color:#fff;background:var(--navy);border-color:var(--navy)}.btn-accent{color:#fff;background:var(--blue);border-color:var(--blue)}.btn:disabled{background:#e2e8f0;color:#69778b;border-color:#e2e8f0;cursor:not-allowed}.btn-sm{padding:8px 12px;font-size:13px}.btn:hover:not(:disabled){filter:brightness(.95)}
        .card{border:1px solid var(--line);box-shadow:var(--shadow);border-radius:var(--radius);background:#fff;padding:22px}.subtle{color:var(--sub)}.micro{font-size:12px;color:var(--sub)}.eyebrow{font-weight:850;color:var(--blue);font-size:12px;letter-spacing:.11em;text-transform:uppercase}.tag{display:inline-flex;align-items:center;gap:6px;font-size:12px;border-radius:99px;padding:5px 10px;background:#eef2fa;color:#47556b;font-weight:750}.tag.good{background:#e7f7ef;color:#13734a}.tag.warn{background:#fff4e7;color:#9b521d}.tag.off{background:#eef1f6;color:#69778b}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px}.flex{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.split{display:flex;align-items:center;justify-content:space-between;gap:15px}.mt{margin-top:24px}.section{margin:26px 0 0}.alert{padding:13px 16px;border-radius:10px;background:#fff6eb;border:1px solid #f9d4b0;color:#874d1c;margin-bottom:17px}.alert.ok{background:#ecfaf2;color:#176443;border-color:#bde8cc}.alert p{color:inherit;margin:0}
        .marketing-bar{background:#fff;border-bottom:1px solid var(--line)}.marketing-nav{max-width:1200px;margin:auto;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px}.marketing-links{display:flex;align-items:center;gap:28px;font-weight:650;font-size:14px}.marketing-main{max-width:1200px;margin:0 auto;padding:0 24px}.marketing-footer{max-width:1200px;margin:80px auto 0;padding:28px 24px;color:var(--sub);border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:15px;flex-wrap:wrap}
        .workspace-shell{min-height:100vh;display:grid;grid-template-columns:258px minmax(0,1fr)}.workspace-side{background:#fff;border-right:1px solid var(--line);padding:27px 16px;display:flex;flex-direction:column;gap:30px}.workspace-side .brand{margin-left:12px}.workspace-name{font-size:13px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;padding:12px;color:#243049;overflow-wrap:anywhere}.side-group{color:#7f8ba0;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.12em;margin:0 12px 10px}.side-link{display:flex;gap:10px;align-items:center;padding:11px 12px;color:#49566f;border-radius:9px;font-weight:650;font-size:14px}.side-link:hover,.side-link.active{color:#1d3b86;background:#edf2ff}.side-footer{margin-top:auto;font-size:12px;color:var(--sub);padding:10px}.logout{border:0;background:none;color:#4b5871;padding:8px 0;text-decoration:underline}.workspace-body{min-width:0}.workspace-top{height:76px;padding:0 36px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px}.workspace-content{max-width:1320px;margin:auto;padding:32px 36px 75px}.page-title{margin-bottom:25px}.page-title h1{font-size:32px;margin-bottom:5px}.stat b{display:block;font-size:32px;letter-spacing:-.045em;margin-top:8px}.stat small{color:var(--sub)}.steps{list-style:none;margin:0;padding:0}.steps li{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}.steps li:last-child{border:0}.step-mark{height:29px;width:29px;display:grid;place-items:center;border:1px solid #d0daeb;border-radius:50%;font-weight:800;color:#9aa7b9;flex-shrink:0}.step-mark.done{background:#e6f8ef;border-color:#b8e7ce;color:#16815b}.steps a{color:#2753c1;text-decoration:underline;text-underline-offset:3px}.progress{height:8px;border-radius:20px;background:#e8edf6;overflow:hidden}.progress span{display:block;height:100%;background:var(--blue);border-radius:20px}.table-wrap{overflow:auto}.data-table{border-collapse:collapse;width:100%}.data-table th,.data-table td{padding:13px 10px;border-bottom:1px solid var(--line);text-align:left;white-space:normal}.data-table th{font-size:12px;color:var(--sub);font-weight:800}.empty{border:1px dashed #cbd6e9;border-radius:14px;padding:32px;text-align:center;background:#f9fbff}.empty h3{margin-bottom:5px}
        .form-field{margin-bottom:17px}.form-field label{display:block;font-weight:760;margin-bottom:7px}.form-field input{padding:12px 13px;border:1px solid #cbd5e1;border-radius:10px;width:100%;background:#fff}.form-field small{color:var(--sub)}.error{display:block;color:#b91c1c;font-size:13px;margin-top:5px}.auth-wrap{max-width:460px;margin:65px auto 0;padding:0 20px}.auth-wrap .card{padding:32px}.auth-wrap h1{font-size:29px}.auth-wrap .brand{margin-bottom:35px}.auth-submit{width:100%;margin-top:9px}.fine-print{text-align:center;font-size:13px;margin-top:22px}
        .plan-card{display:flex;flex-direction:column;gap:14px}.plan-card h3{font-size:22px;margin-bottom:0}.plan-price{font-size:39px;font-weight:900;letter-spacing:-.06em}.plan-price small{font-size:14px;font-weight:500;color:var(--sub);letter-spacing:0}.plan-card ul{list-style:none;padding:0;margin:8px 0 13px;flex:1}.plan-card li{padding:8px 0;border-bottom:1px solid #eef1f6;font-size:14px}.plan-card li::before{content:"✓";color:var(--good);font-weight:900;margin-right:9px}.plan-featured{border-color:#6380ed;box-shadow:0 15px 36px rgba(59,98,233,.11)}
        @media(max-width:950px){.workspace-shell{grid-template-columns:1fr}.workspace-side{position:static;display:block;padding:15px;border-right:0;border-bottom:1px solid var(--line)}.workspace-side .brand{margin:0}.workspace-name,.side-group,.side-footer{display:none}.sidebar-links{display:flex;flex-wrap:nowrap;overflow:auto;gap:5px;margin-top:12px}.side-link{white-space:nowrap;padding:9px 11px}.workspace-top{height:auto;min-height:60px;padding:12px 20px}.workspace-content{padding:24px 20px 60px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:620px){.grid{grid-template-columns:1fr}.marketing-links{gap:12px;font-size:13px}.marketing-nav{padding:15px}.marketing-main{padding:0 18px}.workspace-top{align-items:flex-start;flex-direction:column}.card{padding:17px}.auth-wrap{margin:27px auto 0}.marketing-footer{margin-top:45px}}
    </style>
    @stack('styles')
</head>
<body>
@if(isset($workspace))
<div class="workspace-shell">
    <aside class="workspace-side" aria-label="Workspace navigation">
        <a href="{{ route('saas.workspace.dashboard', $workspace->public_id) }}" class="brand"><span class="brand-mark">D</span> {{ config('saas.public_name') }}</a>
        <div class="workspace-name"><strong>{{ $workspace->name }}</strong><div class="micro">Your private workspace</div></div>
        <nav class="sidebar-links">
            <a class="side-link {{ request()->routeIs('saas.workspace.dashboard') ? 'active' : '' }}" href="{{ route('saas.workspace.dashboard', $workspace->public_id) }}">⌂ &nbsp; Overview</a>
            <a class="side-link {{ request()->routeIs('saas.workspace.products') ? 'active' : '' }}" href="{{ route('saas.workspace.products', $workspace->public_id) }}">▣ &nbsp; My Products</a>
            <a class="side-link {{ request()->routeIs('saas.workspace.connections') ? 'active' : '' }}" href="{{ route('saas.workspace.connections', $workspace->public_id) }}">⇄ &nbsp; Connections</a>
            <a class="side-link {{ request()->routeIs('saas.workspace.team') ? 'active' : '' }}" href="{{ route('saas.workspace.team', $workspace->public_id) }}">♙ &nbsp; Team</a>
            <a class="side-link {{ request()->routeIs('saas.workspace.billing') ? 'active' : '' }}" href="{{ route('saas.workspace.billing', $workspace->public_id) }}">▤ &nbsp; Billing & Plans</a>
            <a class="side-link {{ request()->routeIs('saas.workspace.settings') ? 'active' : '' }}" href="{{ route('saas.workspace.settings', $workspace->public_id) }}">⚙ &nbsp; Settings</a>
        </nav>
        <div class="side-footer">Role: {{ ucfirst($role ?? 'member') }}
        <form method="post" action="{{ route('saas.logout') }}">@csrf<button type="submit" class="logout">Sign out</button></form></div>
    </aside>
    <div class="workspace-body">
        <header class="workspace-top"><strong>{{ $workspace->name }} <span class="tag {{ $activeSubscription ?? false ? 'good' : 'warn' }}">{{ $activeSubscription ?? false ? 'Subscription active' : 'Subscription required' }}</span></strong><span class="subtle">{{ auth()->user()->email }}</span></header>
        <main class="workspace-content">
            @if(session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
            @if(session('error'))<div class="alert" role="alert">{{ session('error') }}</div>@endif
            @yield('content')
        </main>
    </div>
</div>
@else
    <header class="marketing-bar"><nav class="marketing-nav" aria-label="Main navigation">
        <a href="{{ route('saas.landing') }}" class="brand"><span class="brand-mark">D</span> {{ config('saas.public_name') }}</a>
        <div class="marketing-links">
            <a href="{{ route('saas.landing') }}">How it works</a>
            <a href="{{ route('saas.pricing') }}">Pricing</a>
            <a href="{{ route('saas.login') }}" class="btn btn-sm">Sign in</a>
        </div>
    </nav></header>
    <main>@yield('content')</main>
    <footer class="marketing-footer"><span>© {{ date('Y') }} {{ config('saas.public_name') }}. Manage product data with clarity.</span><span>CJ → Botble first · More integrations planned</span></footer>
@endif
</body>
</html>
