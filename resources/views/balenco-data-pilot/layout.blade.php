<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Data Pilot')</title>
<!-- BDP_DATA_PILOT_FAVICON_START -->
<link rel="icon" href="{{ asset('bdp-assets/data-pilot-favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" href="{{ asset('bdp-assets/data-pilot-favicon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('bdp-assets/data-pilot-apple-touch-icon.png') }}">
<!-- BDP_DATA_PILOT_FAVICON_END -->

    <style>
        :root{
            --bdp-bg:#f7f8fb;
            --bdp-surface:#ffffff;
            --bdp-border:#e6eaf0;
            --bdp-muted:#64748b;
            --bdp-text:#0f172a;
            --bdp-navy:#101b36;
            --bdp-navy-2:#1e2a44;
            --bdp-orange:#ff6b1a;
            --bdp-orange-dark:#e8580c;
            --bdp-green:#16a34a;
            --bdp-red:#ef4444;
            --bdp-blue:#2563eb;
            --bdp-radius:16px;
            --bdp-shadow:0 10px 30px rgba(15,23,42,.055);
        }
        *{box-sizing:border-box}
        body{font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;background:var(--bdp-bg);color:var(--bdp-text);margin:0;font-size:14px;line-height:1.45}
        a{color:inherit}.bdp-app{min-height:100vh;display:grid;grid-template-columns:280px 1fr}.bdp-sidebar{position:sticky;top:0;height:100vh;background:#fff;border-right:1px solid var(--bdp-border);padding:20px 18px;display:flex;flex-direction:column;gap:20px;z-index:20}.bdp-brand{display:flex;align-items:center;gap:11px;font-weight:800;font-size:18px;color:var(--bdp-navy);text-decoration:none}.bdp-brand-mark{width:34px;height:34px;border-radius:12px;background:linear-gradient(135deg,#0f172a,#1e40af);display:grid;place-items:center;color:#fff;box-shadow:0 8px 20px rgba(30,64,175,.18)}.bdp-brand-mark svg{width:20px;height:20px}.bdp-collapse-btn{position:absolute;right:-14px;top:78px;width:28px;height:28px;border:1px solid var(--bdp-border);background:#fff;border-radius:999px;display:grid;place-items:center;box-shadow:0 8px 20px rgba(15,23,42,.08);cursor:pointer;color:#334155}.bdp-nav{display:flex;flex-direction:column;gap:6px}.bdp-nav-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin:8px 10px}.bdp-nav a{display:flex;align-items:center;gap:12px;text-decoration:none;color:#334155;padding:12px 13px;border-radius:12px;font-weight:650;transition:.15s ease}.bdp-nav a:hover{background:#f1f5f9;color:#0f172a}.bdp-nav a.active{background:#eef3ff;color:#0f172a;box-shadow:inset 3px 0 0 var(--bdp-navy)}.bdp-nav svg{width:19px;height:19px;color:#53627a}.bdp-nav a.active svg{color:var(--bdp-navy)}.bdp-sidebar-bottom{margin-top:auto}.bdp-sidebar-footer{display:flex;align-items:center;gap:10px;color:#64748b;font-weight:650;padding:10px 12px;border-radius:12px;cursor:pointer}.bdp-main{min-width:0}.bdp-topbar{height:74px;background:rgba(255,255,255,.86);backdrop-filter:blur(14px);border-bottom:1px solid var(--bdp-border);display:flex;align-items:center;gap:18px;padding:0 34px;position:sticky;top:0;z-index:10}.bdp-search{position:relative;width:min(520px,45vw)}.bdp-search input{height:44px;border:1px solid var(--bdp-border);border-radius:14px;background:#fff;padding:0 44px;color:var(--bdp-text);box-shadow:0 4px 16px rgba(15,23,42,.025)}.bdp-search svg{position:absolute;left:15px;top:12px;width:19px;height:19px;color:#64748b}.bdp-kbd{position:absolute;right:10px;top:9px;font-size:12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:3px 8px;color:#64748b}.bdp-top-actions{margin-left:auto;display:flex;align-items:center;gap:12px}.bdp-icon-btn{width:38px;height:38px;border:1px solid var(--bdp-border);background:#fff;border-radius:12px;display:grid;place-items:center;color:#334155;position:relative}.bdp-bell-dot{position:absolute;top:-5px;right:-5px;background:var(--bdp-orange);color:#fff;border-radius:999px;font-size:10px;line-height:18px;min-width:18px;text-align:center}.bdp-user{display:flex;align-items:center;gap:10px;padding-left:8px}.bdp-avatar{width:38px;height:38px;border-radius:999px;background:var(--bdp-navy);color:#fff;display:grid;place-items:center;font-weight:800}.bdp-user strong{display:block;font-size:13px}.bdp-user small{display:block;color:#64748b;font-size:12px}.bdp-content{padding:28px 34px 38px;max-width:1600px}.bdp-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}.bdp-page-title h1{font-size:30px;line-height:1.1;margin:0 0 7px;color:#0f172a}.bdp-page-title p{margin:0;color:#64748b}.bdp-status-toast{display:inline-flex;align-items:center;gap:9px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:13px;padding:12px 16px;font-weight:650}.bdp-dot{width:8px;height:8px;border-radius:999px;background:currentColor}.bdp-card{background:#fff;border:1px solid var(--bdp-border);border-radius:var(--bdp-radius);box-shadow:var(--bdp-shadow);padding:20px;margin-bottom:18px}.bdp-card-header{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.bdp-card-title{margin:0;font-size:17px;color:#0f172a}.bdp-card-subtitle{margin:4px 0 0;color:#64748b;font-size:13px}.bdp-grid{display:grid;gap:18px}.bdp-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.bdp-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.bdp-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}.bdp-grid-5{grid-template-columns:repeat(5,minmax(0,1fr))}.bdp-stats{grid-template-columns:repeat(8,minmax(130px,1fr));margin-bottom:22px}.bdp-stat-card{background:#fff;border:1px solid var(--bdp-border);border-radius:16px;padding:18px;display:flex;align-items:center;gap:13px;min-height:94px;box-shadow:var(--bdp-shadow)}.bdp-stat-icon{width:44px;height:44px;border-radius:16px;display:grid;place-items:center;flex:0 0 auto}.bdp-stat-icon svg{width:23px;height:23px}.bdp-stat-label{font-size:12px;color:#64748b;font-weight:750}.bdp-stat-number{font-size:24px;line-height:1.1;font-weight:850;margin:3px 0;color:#0f172a}.bdp-stat-note{font-size:12px;color:#64748b}.is-blue{background:#eff6ff;color:#2563eb}.is-green{background:#ecfdf5;color:#16a34a}.is-orange{background:#fff7ed;color:#f97316}.is-red{background:#fef2f2;color:#ef4444}.is-purple{background:#f5f3ff;color:#7c3aed}.is-gray{background:#f1f5f9;color:#475569}.bdp-actions-row{display:grid;grid-template-columns:1.1fr 1.1fr 1fr 1fr 56px;gap:16px;margin-bottom:22px}.bdp-action-tile{border:1px solid var(--bdp-border);background:#fff;border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;text-decoration:none;box-shadow:var(--bdp-shadow);min-height:76px}.bdp-action-tile strong{display:block;font-size:15px}.bdp-action-tile small{display:block;color:#64748b;font-size:12px;margin-top:3px}.bdp-action-tile.primary{background:var(--bdp-navy);color:#fff;border-color:var(--bdp-navy)}.bdp-action-tile.primary small,.bdp-action-tile.orange small{color:rgba(255,255,255,.82)}.bdp-action-tile.orange{background:linear-gradient(135deg,var(--bdp-orange),#ff4d00);color:#fff;border-color:transparent}.bdp-action-icon{width:36px;height:36px;border-radius:13px;background:rgba(255,255,255,.13);display:grid;place-items:center}.bdp-action-tile:not(.primary):not(.orange) .bdp-action-icon{background:#f8fafc;color:#334155}.bdp-action-icon svg{width:20px;height:20px}.bdp-btn,.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--bdp-navy);color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;border:1px solid transparent;cursor:pointer;font-weight:750;font-size:13px;line-height:1.2}.bdp-btn.orange,.btn.orange{background:var(--bdp-orange);border-color:var(--bdp-orange)}.bdp-btn.orange:hover,.btn.orange:hover{background:var(--bdp-orange-dark)}.bdp-btn.light,.btn.light{background:#fff;color:#334155;border-color:var(--bdp-border)}.bdp-btn.ghost{background:transparent;color:#334155;border-color:transparent}.bdp-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}label{display:block;font-size:12px;font-weight:750;color:#334155;margin-bottom:7px}input,textarea,select{width:100%;padding:10px 12px;border:1px solid #d8dee8;border-radius:10px;background:#fff;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s}input:focus,textarea:focus,select:focus{border-color:#93c5fd;box-shadow:0 0 0 4px rgba(37,99,235,.08)}textarea{min-height:110px}.bdp-input-help,small{font-size:12px;color:#64748b}.bdp-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.bdp-toolbar .spacer{flex:1}.bdp-filter-tabs{display:flex;gap:8px;flex-wrap:wrap}.bdp-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800;border:1px solid transparent}.bdp-pill.success{background:#dcfce7;color:#166534}.bdp-pill.danger{background:#fee2e2;color:#991b1b}.bdp-pill.warning{background:#ffedd5;color:#9a3412}.bdp-pill.muted{background:#f1f5f9;color:#475569}.bdp-pill.info{background:#dbeafe;color:#1d4ed8}.bdp-alert{padding:12px 14px;border-radius:13px;margin-bottom:16px;border:1px solid}.bdp-alert.success,.msg.success{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}.bdp-alert.error,.msg.error{background:#fef2f2;color:#991b1b;border-color:#fecaca}.msg{padding:12px 14px;border-radius:13px;margin-bottom:16px;border:1px solid}.bdp-table-wrap{overflow:auto;border:1px solid var(--bdp-border);border-radius:16px;background:#fff}.bdp-table,table{width:100%;border-collapse:separate;border-spacing:0}.bdp-table th,table th{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;background:#f8fafc}.bdp-table th,.bdp-table td,table th,table td{padding:13px 14px;border-bottom:1px solid #edf1f7;text-align:left;vertical-align:top}.bdp-table tr:last-child td,table tr:last-child td{border-bottom:0}.bdp-table tbody tr:hover{background:#fbfcff}.bdp-product-cell{display:flex;align-items:flex-start;gap:12px;min-width:250px}.thumb,.bdp-thumb{width:58px;height:58px;object-fit:cover;border-radius:12px;border:1px solid var(--bdp-border);background:#f8fafc;flex:0 0 auto}.bdp-product-title{font-weight:800;color:#0f172a;line-height:1.25}.bdp-product-meta{font-size:12px;color:#64748b;margin-top:3px;line-height:1.35}.bdp-checkbox{width:16px;height:16px}.bdp-dashboard-grid{display:grid;grid-template-columns:1.2fr 1.25fr 1.15fr;gap:18px}.bdp-donut{width:180px;height:180px;border-radius:50%;background:conic-gradient(#22c55e 0 66%,#f59e0b 66% 74%,#8b5cf6 74% 76%,#ef4444 76% 76%,#e5e7eb 76% 100%);display:grid;place-items:center;position:relative}.bdp-donut:after{content:"";position:absolute;inset:25px;border-radius:50%;background:#fff}.bdp-donut-inner{position:relative;z-index:1;text-align:center}.bdp-donut-inner strong{font-size:28px;display:block}.bdp-legend{display:grid;gap:10px}.bdp-legend-row{display:flex;align-items:center;justify-content:space-between;gap:10px;color:#334155}.bdp-activity{display:grid;gap:12px}.bdp-activity-row{display:grid;grid-template-columns:16px 1fr auto;gap:10px;align-items:start}.bdp-activity-dot{width:9px;height:9px;border:2px solid currentColor;border-radius:999px;margin-top:6px}.bdp-activity-row strong{display:block}.bdp-activity-row small{display:block}.bdp-info-list{display:grid;gap:0}.bdp-info-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid #edf1f7}.bdp-info-row:last-child{border-bottom:0}.bdp-review-list{display:grid;gap:10px}.bdp-review-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid #edf1f7;border-radius:13px;background:#fff}.bdp-two-col-main{display:grid;grid-template-columns:.72fr 1.28fr .95fr;gap:18px} .bdp-footer-note{display:flex;align-items:center;justify-content:space-between;color:#64748b;font-size:12px;margin-top:12px}
        .bdp-card.is-compact{min-height:auto}.bdp-card.is-balanced{height:100%}.bdp-empty-state{border:1px dashed #dbe3ee;background:#fbfdff;border-radius:14px;padding:18px;display:flex;align-items:center;justify-content:space-between;gap:14px;color:#475569}.bdp-empty-state strong{display:block;color:#0f172a;margin-bottom:3px}.bdp-empty-state .bdp-btn{white-space:nowrap}.bdp-product-title,.bdp-product-title a{max-width:240px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-decoration:none}.bdp-product-meta{max-width:240px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.bdp-recent-products .bdp-table th,.bdp-recent-products .bdp-table td{padding:11px 12px}.bdp-recent-products .bdp-thumb{width:52px;height:52px}.bdp-dashboard-card-short{min-height:260px}.bdp-review-list{display:grid;gap:10px}.bdp-review-row{text-decoration:none}.bdp-actions-row .bdp-action-tile{transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease}.bdp-actions-row .bdp-action-tile:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(15,23,42,.075);border-color:#d4dce8} .bdp-collapsed .bdp-search{width:min(640px,52vw)}
        .bdp-dashboard-grid,.bdp-two-col-main{align-items:start}
        .bdp-two-col-main{grid-template-columns:.56fr 1.58fr .92fr}
        .bdp-two-col-main>.bdp-card{height:auto;align-self:start}
        .bdp-stats{grid-template-columns:repeat(8,minmax(142px,1fr))}
        .bdp-stat-card{padding:16px;min-height:88px}
        .bdp-stat-icon{width:40px;height:40px;border-radius:14px}
        .bdp-stat-label{white-space:nowrap;font-size:11px;letter-spacing:-.01em}
        .bdp-stat-number{font-size:25px}
        .bdp-recent-products .bdp-table th:nth-child(2),.bdp-recent-products .bdp-table td:nth-child(2){min-width:230px;width:42%}
        .bdp-recent-products .bdp-table th:nth-child(3),.bdp-recent-products .bdp-table td:nth-child(3){white-space:nowrap}
        .bdp-recent-products .bdp-product-title,.bdp-recent-products .bdp-product-title a{max-width:270px}
        .bdp-recent-products .bdp-product-meta{max-width:270px}
        .bdp-dashboard-grid .bdp-card{min-height:0}
        .bdp-card.compact-natural{min-height:0}
        .bdp-small-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .bdp-small-metric{background:#f8fafc;border:1px solid #edf1f7;border-radius:14px;padding:16px;text-align:center}
        .bdp-small-metric strong{display:block;font-size:25px;line-height:1.1;color:#0f172a}
        .bdp-small-metric span{display:block;margin-top:5px;font-size:12px;color:#64748b}
        .bdp-card-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
        .bdp-hide-mobile{display:initial}.bdp-mobile-menu{display:none}.bdp-collapsed .bdp-app{grid-template-columns:92px 1fr}.bdp-collapsed .bdp-sidebar{padding-left:14px;padding-right:14px}.bdp-collapsed .bdp-brand span,.bdp-collapsed .bdp-nav span,.bdp-collapsed .bdp-nav-label,.bdp-collapsed .bdp-sidebar-footer span{display:none}.bdp-collapsed .bdp-nav a{justify-content:center;padding:12px}.bdp-collapsed .bdp-brand{justify-content:center}.bdp-collapsed .bdp-collapse-btn svg{transform:rotate(180deg)}
        @media(max-width:1300px){.bdp-stats{grid-template-columns:repeat(4,minmax(0,1fr))}.bdp-dashboard-grid,.bdp-two-col-main{grid-template-columns:1fr 1fr}.bdp-two-col-main>.bdp-card:last-child{grid-column:1/-1}.bdp-recent-products .bdp-table th:nth-child(2),.bdp-recent-products .bdp-table td:nth-child(2){min-width:190px}}
        @media(max-width:980px){.bdp-app{grid-template-columns:1fr}.bdp-sidebar{position:fixed;transform:translateX(-100%);transition:.2s ease;width:280px}.bdp-mobile-open .bdp-sidebar{transform:translateX(0)}.bdp-collapse-btn{display:none}.bdp-mobile-menu{display:grid}.bdp-topbar{padding:0 18px}.bdp-search{width:100%}.bdp-user small,.bdp-user strong{display:none}.bdp-content{padding:22px 18px}.bdp-page-head{display:block}.bdp-status-toast{margin-top:14px}.bdp-actions-row,.bdp-dashboard-grid,.bdp-two-col-main,.bdp-grid-2,.bdp-grid-3,.bdp-grid-4,.bdp-grid-5{grid-template-columns:1fr}.bdp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:640px){.bdp-stats{grid-template-columns:1fr}.bdp-toolbar{align-items:stretch}.bdp-toolbar>*{width:100%}.bdp-hide-mobile{display:none}.bdp-page-title h1{font-size:25px}}
    


        /* Consistent navigation, accessible focus and real global product search. */
        .bdp-sidebar{overflow-y:auto;scrollbar-width:thin}
        .bdp-nav{flex:1;min-height:0;overflow-y:auto;scrollbar-width:thin;padding-bottom:8px}
        .bdp-sidebar-bottom{flex:0 0 auto}
        .bdp-nav-label{margin:14px 10px 6px;font-weight:800}
        .bdp-nav a{font-size:13px;min-height:43px;white-space:nowrap}
        .bdp-nav a.active{background:#ebf1fd;box-shadow:inset 3px 0 0 var(--bdp-navy)}
        .bdp-search{width:min(490px,43vw);height:44px}
        .bdp-search input{width:100%;height:44px;padding:0 94px 0 44px}
        .bdp-search svg{pointer-events:none}
        .bdp-search-submit{position:absolute;right:5px;top:5px;bottom:5px;border:0;border-radius:9px;background:#f1f5f9;color:#334155;padding:0 14px;cursor:pointer;font-weight:750;font-size:12px}
        .bdp-search-submit:hover{background:#e5edf6}
        .bdp-icon-btn{flex-shrink:0;text-decoration:none;cursor:pointer}
        .bdp-sidebar-backdrop{display:none}
        .bdp-search :focus-visible,.bdp-nav a:focus-visible,.bdp-icon-btn:focus-visible,.bdp-sync-btn:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
        .bdp-collapsed .bdp-nav a{position:relative}
        .bdp-collapsed .bdp-nav a:focus-visible{outline-offset:-1px}
        .bdp-actions-row{grid-template-columns:1.15fr 1.15fr 1fr 1fr 1fr}
        @media(max-width:1300px){.bdp-actions-row{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:980px){
            .bdp-sidebar-backdrop{display:block;position:fixed;inset:0;border:0;background:rgba(15,23,42,.42);z-index:19;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .2s ease,visibility .2s ease}
            .bdp-mobile-open .bdp-sidebar-backdrop{opacity:1;visibility:visible;pointer-events:auto}
            .bdp-collapsed .bdp-sidebar{width:280px;padding:20px 18px}
            .bdp-collapsed .bdp-brand{justify-content:flex-start}
            .bdp-collapsed .bdp-brand span,.bdp-collapsed .bdp-nav span,.bdp-collapsed .bdp-nav-label{display:initial}
            .bdp-collapsed .bdp-nav a{justify-content:flex-start;padding:12px 13px}
            .bdp-topbar{gap:10px}
            .bdp-search{width:min(480px,60vw)}
            .bdp-actions-row{grid-template-columns:repeat(2,minmax(0,1fr))}
        }
        @media(max-width:640px){
            .bdp-search{width:100%;min-width:0}
            .bdp-search input{padding-right:62px}
            .bdp-search-submit{padding:0 8px;font-size:11px}
            .bdp-top-actions{gap:8px}
            .bdp-avatar{width:32px;height:32px}
            .bdp-actions-row{grid-template-columns:1fr}
        }
        @media(prefers-reduced-motion:reduce){.bdp-sidebar-backdrop{transition:none}.bdp-nav a,.bdp-actions-row .bdp-action-tile{transition:none}}

/* BDP Data Pilot logo/favicon brand patch */
.bdp-brand-logo-img{
    width:34px;
    height:34px;
    border-radius:12px;
    object-fit:contain;
    display:block;
    background:#fff;
}
.bdp-brand .bdp-brand-logo-img + span{
    font-weight:850;
    letter-spacing:-.02em;
}

</style>



</head>
<body>
@php
    $bdpAdminProfileName = 'Admin';
    $bdpAdminProfileEmail = null;
    try {
        if (class_exists(\App\Models\BdpAdminProfile::class) && \Illuminate\Support\Facades\Schema::hasTable('bdp_admin_profiles')) {
            $bdpAdminProfile = \App\Models\BdpAdminProfile::query()->first();
            if ($bdpAdminProfile) {
                $bdpAdminProfileName = $bdpAdminProfile->name ?: 'Admin';
                $bdpAdminProfileEmail = $bdpAdminProfile->email;
            }
        }
    } catch (\Throwable $e) {}
@endphp
@php
    // Only registered, working pages appear in navigation. Grouping is
    // consistent on Dashboard, Products, Sync Queue and connection screens.
    $navGroups = [
        'Workspace' => [
            ['label' => 'Dashboard', 'route' => 'bdp.index', 'match' => 'bdp.index', 'icon' => 'home'],
            ['label' => 'Products', 'route' => 'bdp.products', 'match' => 'bdp.products*', 'icon' => 'box'],
            ['label' => 'Import from CJ', 'route' => 'bdp.cj.import', 'match' => 'bdp.cj.import*', 'icon' => 'download'],
            ['label' => 'CSV / Excel Import', 'route' => 'bdp.import.form', 'match' => 'bdp.import*', 'icon' => 'file'],
            ['label' => 'Sync Queue', 'route' => 'bdp.sync.queue.index', 'match' => 'bdp.sync.queue.*', 'icon' => 'queue'],
        ],
        'Connections' => [
            ['label' => 'CJ API Settings', 'route' => 'bdp.cj.settings', 'match' => 'bdp.cj.settings*', 'icon' => 'settings'],
            ['label' => 'Category Mapping', 'route' => 'bdp.categories.mapping', 'match' => 'bdp.categories*', 'icon' => 'grid'],
            ['label' => 'Store & Pricing', 'route' => 'bdp.settings', 'match' => 'bdp.settings*', 'icon' => 'settings'],
        ],
        'Administration' => [
            ['label' => 'API Logs', 'route' => 'bdp.api.logs', 'match' => 'bdp.api.logs*', 'icon' => 'file'],
            ['label' => 'Admin Profile', 'route' => 'bdp.profile', 'match' => 'bdp.profile*', 'icon' => 'user'],
        ],
    ];
    $bdpInitials = collect(preg_split('/\s+/', trim((string) $bdpAdminProfileName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr((string) $part, 0, 1)))
        ->implode('') ?: 'AD';
@endphp
<div class="bdp-app-shell" id="bdpAppShell">
    <button class="bdp-sidebar-backdrop" type="button" aria-label="Close navigation" onclick="bdpToggleMobileSidebar(false)"></button>
    <div class="bdp-app">
        <aside class="bdp-sidebar" id="bdpSidebar">
            <a class="bdp-brand" href="{{ route('bdp.index') }}">
                <img class="bdp-brand-logo-img" src="{{ asset('bdp-assets/data-pilot-icon.png') }}" alt="Data Pilot">
                <span>Data Pilot</span>
            </a>
            <button type="button" class="bdp-collapse-btn" onclick="bdpToggleSidebar()" title="Collapse or expand sidebar" aria-label="Collapse or expand sidebar">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <nav class="bdp-nav" aria-label="Main navigation">
                @foreach($navGroups as $groupLabel => $groupItems)
                    <div class="bdp-nav-label">{{ $groupLabel }}</div>
                    @foreach($groupItems as $item)
                    <a href="{{ route($item['route']) }}" class="{{ request()->routeIs($item['match']) ? 'active' : '' }}" @if(request()->routeIs($item['match'])) aria-current="page" @endif title="{{ $item['label'] }}">
                        @if($item['icon'] === 'home')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 10 9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>@endif
                        @if($item['icon'] === 'box')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>@endif
                        @if($item['icon'] === 'download')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 17v3h16v-3"/></svg>@endif
                        @if($item['icon'] === 'sync')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-15.5-6.2L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15.5 6.2L21 16"/><path d="M16 16h5v5"/></svg>@endif
                        @if($item['icon'] === 'settings')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.04.04a2 2 0 1 1-2.83 2.83l-.04-.04a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.06a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.04.04a2 2 0 1 1-2.83-2.83l.04-.04A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.06a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.88l-.04-.04a2 2 0 1 1 2.83-2.83l.04.04A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.06a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.04-.04a2 2 0 1 1 2.83 2.83l-.04.04a1.7 1.7 0 0 0-.34 1.88c.2.62.79 1.04 1.43 1.04H21a2 2 0 1 1 0 4h-.06a1.7 1.7 0 0 0-1.54 1Z"/></svg>@endif
                        @if($item['icon'] === 'grid')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>@endif
                        @if($item['icon'] === 'file')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/></svg>@endif
                        @if($item['icon'] === 'queue')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M7 9v4h10V9M3 16h18M7 20h10"/></svg>@endif
                        @if($item['icon'] === 'user')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>@endif
                        <span>{{ $item['label'] }}</span>
                    </a>
                    @endforeach
                @endforeach
            </nav>
            <div class="bdp-sidebar-bottom">
                <form method="POST" action="{{ route('bdp.logout') }}">
                    @csrf
                    <button class="bdp-btn light" style="width:100%" type="submit">Logout</button>
                </form>
            </div>
        </aside>
        <main class="bdp-main">
            <header class="bdp-topbar">
                <button class="bdp-icon-btn bdp-mobile-menu" id="bdpMobileMenuBtn" type="button" aria-label="Open navigation" aria-controls="bdpSidebar" aria-expanded="false" onclick="bdpToggleMobileSidebar()">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <form class="bdp-search" role="search" method="GET" action="{{ route('bdp.products') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" name="q" value="{{ request()->routeIs('bdp.products') ? request('q', '') : '' }}" placeholder="Find a product or SKU" aria-label="Search products by name or SKU">
                    <button class="bdp-search-submit" type="submit">Search</button>
                </form>
                <div class="bdp-top-actions">
                    <a class="bdp-icon-btn" href="{{ route('bdp.sync.queue.index') }}" aria-label="Open sync queue" title="Sync Queue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true"><path d="M21 12a9 9 0 0 0-15.5-6.2L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15.5 6.2L21 16"/><path d="M16 16h5v5"/></svg></a>
                    <a class="bdp-icon-btn bdp-hide-mobile" href="{{ route('bdp.cj.settings') }}" aria-label="CJ API settings" title="CJ API Settings"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.04.04a2 2 0 1 1-2.83 2.83l-.04-.04a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.06a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.04.04a2 2 0 1 1-2.83-2.83l.04-.04A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 4 0h.06a1.7 1.7 0 0 0 1-1.55 1.7 1.7 0 0 0-.34-1.88l-.04-.04a2 2 0 1 1 2.83-2.83l.04.04A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.06a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.04-.04A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.54 1H21a2 2 0 1 1 0 4h-.06A1.7 1.7 0 0 0 19.4 15Z"/></svg></a>
                    <div class="bdp-user">
                        <div class="bdp-avatar" aria-hidden="true">{{ $bdpInitials }}</div>
                        <div><strong>{{ $bdpAdminProfileName ?? 'Admin' }}</strong><small>{{ $bdpAdminProfileEmail ?: 'Administrator' }}</small></div>
                    </div>
                </div>
            </header>
            <section class="bdp-content">
                @if(session('success'))<div class="msg success">{{ session('success') }}</div>@endif
                @if(session('error'))<div class="msg error">{{ session('error') }}</div>@endif
                @yield('content')
            </section>
        </main>
    </div>
</div>
<script>
(function(){
    var shell = document.getElementById('bdpAppShell');
    if (!shell) return;
    try {
        if (localStorage.getItem('bdp_sidebar_collapsed') === '1') {
            shell.classList.add('bdp-collapsed');
        }
    } catch (e) {}
})();
function bdpToggleSidebar(){
    var shell = document.getElementById('bdpAppShell');
    if (!shell) return;
    shell.classList.toggle('bdp-collapsed');
    try {
        localStorage.setItem('bdp_sidebar_collapsed', shell.classList.contains('bdp-collapsed') ? '1' : '0');
    } catch (e) {}
}
function bdpToggleMobileSidebar(force){
    var shell = document.getElementById('bdpAppShell');
    var button = document.getElementById('bdpMobileMenuBtn');
    if (!shell) return;
    var shouldOpen = typeof force === 'boolean' ? force : !shell.classList.contains('bdp-mobile-open');
    shell.classList.toggle('bdp-mobile-open',shouldOpen);
    if (button){button.setAttribute('aria-expanded',shouldOpen ? 'true' : 'false');button.setAttribute('aria-label',shouldOpen ? 'Close navigation' : 'Open navigation');}
}
document.addEventListener('keydown',function(event){if(event.key==='Escape'){bdpToggleMobileSidebar(false);}});
document.querySelectorAll('#bdpSidebar .bdp-nav a').forEach(function(link){
    link.addEventListener('click',function(){if(window.innerWidth<=980)bdpToggleMobileSidebar(false);});
});
window.addEventListener('resize',function(){if(window.innerWidth>980)bdpToggleMobileSidebar(false);});
</script>



</body>
</html>
