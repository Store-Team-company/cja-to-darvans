@extends('balenco-data-pilot.layout')

@section('title', 'Settings')

@section('content')

{{-- Read-only environment diagnostics. Passwords and tokens are never displayed. --}}
<div class="bdp-card" style="padding:20px;margin-bottom:20px;border:1px solid #e3e9f2;border-radius:13px;background:#fff;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="margin:0 0 5px">Installation &amp; System</h2>
            <p style="margin:0;color:#64748b">SiteGround readiness and configuration status. Sensitive credentials are only stored in the private application folder.</p>
        </div>
        <span style="font-weight:750;color:#177455">Admin access required</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:16px">
        <div style="padding:12px;background:#f8fafc;border-radius:10px"><small>Application URL</small><strong style="display:block;word-break:break-word">{{ config('app.url') }}</strong></div>
        <div style="padding:12px;background:#f8fafc;border-radius:10px"><small>PHP version</small><strong style="display:block">{{ PHP_VERSION }}</strong></div>
        <div style="padding:12px;background:#f8fafc;border-radius:10px"><small>Database driver</small><strong style="display:block">{{ config('database.default') }}</strong></div>
        <div style="padding:12px;background:#f8fafc;border-radius:10px"><small>Queue driver</small><strong style="display:block">{{ config('queue.default') }}</strong></div>
        <div style="padding:12px;background:#f8fafc;border-radius:10px"><small>Installer status</small><strong style="display:block">{{ file_exists(storage_path('app/.siteground-installed')) ? 'Completed and locked' : 'Manual / existing deployment' }}</strong></div>
        <div style="padding:12px;background:#f8fafc;border-radius:10px"><small>Storage writable</small><strong style="display:block">{{ is_writable(storage_path('app')) ? 'Ready' : 'Check permissions' }}</strong></div>
    </div>
    <p style="margin:13px 0 8px;color:#64748b;font-size:13px">First-time setup is handled by the protected SiteGround installation wizard. For normal administration use the options below.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="bdp-btn light" href="{{ route('bdp.cj.settings') }}">Configure CJ API</a>
        <a class="bdp-btn light" href="{{ route('bdp.cj.import') }}">Import CJ products</a>
        <a class="bdp-btn light" href="{{ route('bdp.profile') }}">Change admin password</a>
    </div>
</div>


{{-- BDP_CONNECTED_STORE_PERSIST_DISPLAY_FIX_START --}}
@php
    $bdpStoreSettingValue = function ($keys, $default = '') {
        $keys = is_array($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            try {
                $value = \App\Models\BdpSetting::getValue($key, null);
            } catch (\Throwable $e) {
                $value = null;
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    };

    $bdpStorePasswordSaved = $bdpStoreSettingValue(['store_db_password', 'connected_store_db_password', 'db_password'], '');
    $bdpStoreApiSecretSaved = $bdpStoreSettingValue(['store_api_secret', 'connected_store_api_secret', 'api_secret'], '');

    $bdpConnectedStoreSaved = [
        'store_push_method' => $bdpStoreSettingValue(['store_push_method', 'push_method'], 'database'),
        'store_db_host' => $bdpStoreSettingValue(['store_db_host', 'connected_store_db_host', 'db_host'], ''),
        'store_db_port' => $bdpStoreSettingValue(['store_db_port', 'connected_store_db_port', 'db_port'], '3306'),
        'store_db_database' => $bdpStoreSettingValue(['store_db_database', 'store_db_name', 'connected_store_db_database', 'db_database'], ''),
        'store_db_username' => $bdpStoreSettingValue(['store_db_username', 'connected_store_db_username', 'db_username'], ''),
        'store_db_password' => $bdpStorePasswordSaved ? '********' : '',
        'store_db_prefix' => $bdpStoreSettingValue(['store_db_prefix', 'connected_store_db_prefix', 'db_prefix'], ''),
        'store_name' => $bdpStoreSettingValue(['store_name', 'connected_store_name'], ''),
        'store_domain' => $bdpStoreSettingValue(['store_domain', 'connected_store_domain'], ''),
        'store_api_url' => $bdpStoreSettingValue(['store_api_url', 'store_admin_api_url', 'connected_store_api_url', 'api_url'], ''),
        'store_api_secret' => $bdpStoreApiSecretSaved ? '********' : '',
        'store_status' => $bdpStoreSettingValue(['store_status', 'connected_store_status'], 'enabled'),
    ];
@endphp
{{-- BDP_CONNECTED_STORE_PERSIST_DISPLAY_FIX_END --}}

@php
    $getSetting = function ($key, $default = '') use ($settings) {
        if (is_array($settings ?? null)) {
            return data_get($settings, $key, $default);
        }

        if (is_object($settings ?? null)) {
            if (method_exists($settings, 'get')) {
                $value = $settings->get($key);
                if ($value !== null) {
                    if (is_object($value) && isset($value->value)) {
                        return $value->value;
                    }
                    return $value;
                }
            }

            if (method_exists($settings, 'firstWhere')) {
                $row = $settings->firstWhere('key', $key);
                if ($row && isset($row->value)) {
                    return $row->value;
                }
            }
        }

        return $default;
    };

    $settingAction = \Illuminate\Support\Facades\Route::has('bdp.settings.update')
        ? route('bdp.settings.update')
        : route('bdp.settings');

    $defaultSyncStatus = $getSetting('default_sync_status', 'draft');
    $defaultShippingCountry = $getSetting('default_shipping_country', 'US');
    $copyVariationImages = (string) $getSetting('copy_variation_images', '1') === '1';
    $pushParentFeatured = (string) $getSetting('push_parent_featured_image', '0') === '1';
    $pushParentGallery = (string) $getSetting('push_parent_gallery_images', '0') === '1';
    $marginType = $getSetting('margin_type', 'fixed');
    $marginValue = $getSetting('margin_value', '20');
    $minimumSellingPrice = $getSetting('minimum_selling_price', '39.99');
    $retailRounding = $getSetting('retail_rounding', '99');
    $balencoDbConnection = $getSetting('balenco_db_connection', 'mysql');

    $aiShortWords = $getSetting('ai_short_words', '40-60');
    $aiLongWords = $getSetting('ai_long_words', '180-230');
    $aiMetaChars = $getSetting('ai_meta_chars', '150-160');
    $aiTagsCount = $getSetting('ai_tags_count', '6');
    $aiDefaultBuyerIntent = $getSetting('ai_default_buyer_intent', 'Everyday wear');
@endphp

<style>
    .bdp-settings-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;align-items:start}
    .bdp-settings-stack{display:flex;flex-direction:column;gap:18px}
    .bdp-settings-card{background:#fff;border:1px solid #e5eaf3;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.04);padding:22px}
    .bdp-settings-card h3{margin:0 0 6px;font-size:20px;font-weight:900;color:#0f172a}
    .bdp-settings-card p{margin:0;color:#64748b;line-height:1.5}
    .bdp-settings-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px}
    .bdp-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .bdp-field label{display:block;font-weight:800;color:#0f172a;font-size:13px;margin-bottom:7px}
    .bdp-field input,.bdp-field select{width:100%;border:1px solid #d8e0ec;border-radius:10px;padding:11px 12px;font-size:14px;background:#fff;color:#0f172a}
    .bdp-field small{display:block;color:#64748b;margin-top:6px;line-height:1.45}
    .bdp-setting-row{display:flex;justify-content:space-between;align-items:center;gap:20px;background:#f8fafc;border:1px solid #eef2f7;border-radius:16px;padding:16px;margin-top:14px}
    .bdp-setting-row strong{display:block;color:#0f172a;font-size:16px;margin-bottom:4px}
    .bdp-switch{position:relative;display:inline-block;width:54px;height:28px;flex:0 0 auto}
    .bdp-switch input{opacity:0;width:0;height:0}
    .bdp-slider{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:999px;transition:.2s}
    .bdp-slider:before{content:"";position:absolute;height:22px;width:22px;left:3px;top:3px;background:white;border-radius:50%;transition:.2s;box-shadow:0 2px 8px rgba(15,23,42,.18)}
    .bdp-switch input:checked + .bdp-slider{background:#0f172a}
    .bdp-switch input:checked + .bdp-slider:before{transform:translateX(26px)}
    .bdp-save-btn{border:0;border-radius:11px;background:#ff5a1f;color:#fff;font-weight:900;padding:12px 22px;cursor:pointer;transition:.18s ease}
    .bdp-save-btn:hover{background:#e84d13;transform:translateY(-1px)}
    .bdp-muted-box{background:#f8fafc;border:1px solid #e8eef6;border-radius:14px;padding:15px;color:#475569;line-height:1.55;margin-top:14px}
    .bdp-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:14px;padding:14px;margin-top:14px;line-height:1.55}
    @media(max-width:1100px){.bdp-settings-grid{grid-template-columns:1fr}.bdp-form-grid{grid-template-columns:1fr}}


/* BDP connected store polish */
.bdp-connected-store-card input,
.bdp-connected-store-card select { width: 100%; }
</style>

{{-- BDP_SETTINGS_CONNECTED_VALUES_START --}}
@php
    $bdpSettingsMap = \App\Models\BdpSetting::query()->pluck('value', 'key')->toArray();
    $bdpSettingValue = function ($key, $default = '') use ($bdpSettingsMap) {
        return old($key, $bdpSettingsMap[$key] ?? $default);
    };
    $bdpStorePushMethod = old('store_push_method', $bdpSettingsMap['store_push_method'] ?? 'database');
    $bdpConnectedStoreEnabled = old('connected_store_enabled', $bdpSettingsMap['connected_store_enabled'] ?? '1');
    $bdpHasStoreDbPassword = !empty($bdpSettingsMap['store_db_password'] ?? '');
    $bdpHasStoreApiSecret = !empty($bdpSettingsMap['store_api_secret'] ?? '');
@endphp
{{-- BDP_SETTINGS_CONNECTED_VALUES_END --}}

<div class="bdp-page-head">
    <div>
        <h1>Settings</h1>
        <p>Configure safe defaults once, so product hunters and data entry users do not accidentally change sync behavior.</p>
    </div>
</div>

<form method="post" action="{{ $settingAction }}">

{{-- BDP_CONNECTED_STORE_SETTINGS_START --}}
<div class="bdp-card bdp-connected-store-card">
    <div class="bdp-card-header">
        <div>
            <h3 class="bdp-card-title">Connected Store</h3>
<p class="bdp-store-clean-note">Add the public store identity, then choose how Data Pilot pushes products.</p>
            <p class="bdp-card-subtitle">Choose the store domain where Data Pilot will push synced products.</p>
        </div>

<!-- BDP_STORE_CONNECTION_METHODS_START -->
<div class="bdp-store-connection-methods" style="margin-top:18px">
    <div class="bdp-section-title" style="font-size:16px;font-weight:800;margin-bottom:6px">Push Connection Method</div>
    <p class="bdp-card-subtitle" style="margin-bottom:14px">Choose how Data Pilot will send products to the connected store.</p>

    <div class="bdp-form-grid">
        <div>
            <label>Push method</label>
            <select name="store_push_method" id="bdp-store-push-method">
                <option value="database" @selected($bdpStorePushMethod === 'database')>Direct database (legacy single-store)</option>
                <option value="api" @selected($bdpStorePushMethod === 'api')>API receiver endpoint</option>
            </select>
            <div class="bdp-input-help">These are legacy single-store importer settings. For customer Botble stores use the SaaS workspace Connections page and the Data Pilot Botble Connector.</div>
        </div>
    </div>

    <div id="bdp-direct-db-settings" class="bdp-method-panel" style="margin-top:14px">
        <div class="bdp-alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:12px 14px;border-radius:12px;margin-bottom:14px">
            Enter a MySQL hostname or IP address (e.g. localhost), NEVER a website URL such as https://sandbox.daystart.pk. Prefer the Botble Connector for customer stores.
        </div>
        <div class="bdp-form-grid">
            <div>
                <label>Database host</label>
                <input type="text" name="store_db_host" value="{{ e($bdpSettingValue('store_db_host')) }}" placeholder="MySQL hostname or IP only — no https://">
            </div>
            <div>
                <label>Database port</label>
                <input type="text" name="store_db_port" value="{{ e($bdpSettingValue('store_db_port', '3306')) }}" placeholder="3306">
            </div>
            <div>
                <label>Database name</label>
                <input type="text" name="store_db_database" value="{{ e($bdpSettingValue('store_db_database')) }}" placeholder="Target store database name">
            </div>
            <div>
                <label>Database username</label>
                <input type="text" name="store_db_username" value="{{ e($bdpSettingValue('store_db_username')) }}" placeholder="Target DB username">
            </div>
            <div>
                <label>Database password</label>
                <input type="password" name="store_db_password" value="" autocomplete="new-password" placeholder="Leave blank to keep current password">
                <div class="bdp-input-help">{{ $bdpHasStoreDbPassword ? 'A password is saved. ' : '' }}Leave blank to keep your current password.</div>
            </div>
            <div>
                <label>Table prefix optional</label>
                <input type="text" name="store_db_prefix" value="{{ e($bdpSettingValue('store_db_prefix')) }}" placeholder="e.g. abc_ or leave blank">
                <div class="bdp-input-help">Use only if the target Botble tables have a custom prefix.</div>
            </div>
        </div>
    </div>

    <div id="bdp-api-settings" class="bdp-method-panel" style="margin-top:14px;display:none">
        <div class="bdp-alert" style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:12px 14px;border-radius:12px;margin-bottom:14px">
            This is a LEGACY API receiver, not the authenticated Data Pilot Botble Connector v0.2. Do not use it as a replacement for tenant-based connector pairing.
        </div>
        <div class="bdp-form-grid">
            <div>
                <label>API receiver endpoint URL</label>
                <input type="url" name="store_api_endpoint" value="{{ e($bdpSettingValue('store_api_endpoint')) }}" placeholder="https://yourstore.com/data-pilot/receive-product">
            </div>
            <div>
                <label>API secret key</label>
                <input type="password" name="store_api_secret" value="" autocomplete="new-password" placeholder="Leave blank to keep current secret">
                <div class="bdp-input-help">The same secret must exist on the target store receiver configuration.</div>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
        <button type="button" class="bdp-btn light" id="bdp-test-store-connection">Test Connection</button>
                                <button type="button" class="bdp-btn orange" id="bdp-save-connected-store" style="margin-left:8px;">Save Connected Store</button>
        <span id="bdp-store-test-result" class="bdp-input-help"></span>
    </div>
</div>
<!-- BDP_STORE_CONNECTION_METHODS_END -->

    </div>

    <div class="bdp-form-grid">
        <div class="bdp-store-name-field">
<label>
            <input type="text" name="connected_store_name" value="{{ e($bdpSettingValue('connected_store_name', 'Balenco Store')) }}" placeholder="e.g. Main Store">
            <div class="bdp-input-help">Internal label only. Example: Main Store, Balenco Live, Test Store.</div>
        </div>

        <div class="bdp-store-domain-field">
<label>
            <input type="text" name="connected_store_domain" value="{{ e($bdpSettingValue('connected_store_domain')) }}" placeholder="e.g. balencousa.com">
            <div class="bdp-input-help">Enter only the domain or full URL. Example: balencousa.com or https://balencousa.com</div>
        </div>

        <div>
            <label>Store admin/API base URL <span style="font-weight:500;color:#64748b">optional</span></label>
            <input type="text" name="connected_store_api_url" value="{{ e($bdpSettingValue('connected_store_api_url')) }}" placeholder="e.g. https://balencousa.com">
            <div class="bdp-input-help">Optional. Use this later if product push needs a separate API URL.</div>
        </div>

        <div>
            <label>Store status</label>
            <select name="connected_store_enabled">
                <option value="1" @selected((string)$bdpConnectedStoreEnabled === '1')>Enabled — allow product push</option>
                <option value="0" @selected((string)$bdpConnectedStoreEnabled === '0')>Disabled — pause product push</option>
            </select>
            <div class="bdp-input-help">Use disabled when testing or when store connection should be paused.</div>
        </div>
    </div>

    @php
        $bdpConnectedDomain = trim($settings['connected_store_domain'] ?? '');
        $bdpConnectedUrl = $bdpConnectedDomain;
        if ($bdpConnectedUrl && !preg_match('/^https?:\/\//i', $bdpConnectedUrl)) {
            $bdpConnectedUrl = 'https://' . $bdpConnectedUrl;
        }
    @endphp

    @if(!empty($bdpConnectedDomain))
        <div class="bdp-settings-note" style="margin-top:14px;padding:12px 14px;border-radius:14px;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;font-size:13px;line-height:1.5;">
            Connected store target:
            <strong>{{ $settings['connected_store_name'] ?? 'Store' }}</strong>
            — <a href="{{ $bdpConnectedUrl }}" target="_blank" rel="noopener" style="color:#166534;text-decoration:underline;font-weight:700;">{{ $bdpConnectedDomain }}</a>
        </div>
    @else
        <div class="bdp-settings-note" style="margin-top:14px;padding:12px 14px;border-radius:14px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px;line-height:1.5;">
            Connected store database settings are saved. Store domain is optional for direct database push.
        </div>
    @endif
</div>
{{-- BDP_CONNECTED_STORE_SETTINGS_END --}}

    @csrf

    <div class="bdp-settings-grid">
        <div class="bdp-settings-stack">
            <div class="bdp-settings-card">
                <div class="bdp-settings-header">
                    <div>
                        <h3>Sync Settings</h3>
                        <p>Admin-only defaults used when syncing products from Data Pilot to Balenco/Shofy.</p>
                    </div>
                    <button class="bdp-save-btn" type="submit">Save Settings</button>
                </div>

                <div class="bdp-form-grid">
                    <div class="bdp-field">
                        <label>Default sync status</label>
                        <select name="default_sync_status">
                            <option value="draft" @selected($defaultSyncStatus === 'draft')>Draft — safest for live store</option>
                            <option value="pending" @selected($defaultSyncStatus === 'pending')>Pending review</option>
                            <option value="published" @selected($defaultSyncStatus === 'published')>Published</option>
                        </select>
                        <small>Recommended: Draft first, then review in Balenco before publishing.</small>
                    </div>

                    <div class="bdp-field">
                        <label>Default shipping country</label>
                        <input type="text" name="default_shipping_country" value="{{ old('default_shipping_country', $defaultShippingCountry) }}" placeholder="US">
                    </div>
                </div>

                <div class="bdp-setting-row">
                    <div>
                        <strong>Copy variation/color images to Balenco</strong>
                        <p>Recommended. Data Pilot copies CJ variation images to Balenco storage and saves full local image URLs.</p>
                    </div>
                    <label class="bdp-switch">
                        <input type="hidden" name="copy_variation_images" value="0">
                        <input type="checkbox" name="copy_variation_images" value="1" @checked($copyVariationImages)>
                        <span class="bdp-slider"></span>
                    </label>
                </div>

                <div class="bdp-setting-row">
                    <div>
                        <strong>Push parent featured image</strong>
                        <p>Normally disabled. Keep parent image manual unless Data Pilot images are final and optimized.</p>
                    </div>
                    <label class="bdp-switch">
                        <input type="hidden" name="push_parent_featured_image" value="0">
                        <input type="checkbox" name="push_parent_featured_image" value="1" @checked($pushParentFeatured)>
                        <span class="bdp-slider"></span>
                    </label>
                </div>

                <div class="bdp-setting-row">
                    <div>
                        <strong>Push parent gallery images</strong>
                        <p>Normally disabled. Balenco gallery images should usually be uploaded manually with optimized lifestyle/product photos.</p>
                    </div>
                    <label class="bdp-switch">
                        <input type="hidden" name="push_parent_gallery_images" value="0">
                        <input type="checkbox" name="push_parent_gallery_images" value="1" @checked($pushParentGallery)>
                        <span class="bdp-slider"></span>
                    </label>
                </div>
            </div>

            <div class="bdp-settings-card">
                <h3>Pricing Defaults</h3>
                <p>Used by bulk calculators and as default guidance for product pricing.</p>

                <div class="bdp-form-grid" style="margin-top:16px">
                    <div class="bdp-field">
                        <label>Margin type</label>
                        <select name="margin_type">
                            <option value="fixed" @selected($marginType === 'fixed')>Fixed amount</option>
                            <option value="percent" @selected($marginType === 'percent')>Percentage</option>
                        </select>
                    </div>

                    <div class="bdp-field">
                        <label>Margin value</label>
                        <input type="text" name="margin_value" value="{{ old('margin_value', $marginValue) }}">
                    </div>

                    <div class="bdp-field">
                        <label>Minimum selling price</label>
                        <input type="text" name="minimum_selling_price" value="{{ old('minimum_selling_price', $minimumSellingPrice) }}">
                    </div>

                    <div class="bdp-field">
                        <label>Retail rounding</label>
                        <select name="retail_rounding">
                            <option value="99" @selected((string)$retailRounding === '99')>Round up to .99</option>
                            <option value="95" @selected((string)$retailRounding === '95')>Round up to .95</option>
                            <option value="00" @selected((string)$retailRounding === '00')>Round to whole number</option>
                            <option value="none" @selected((string)$retailRounding === 'none')>No rounding</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="bdp-settings-card">
                <h3>Balenco Category Source</h3>
                <p>Optional database connection used only if you later decide to fetch store categories for category mapping.</p>

                <div class="bdp-form-grid" style="margin-top:16px">
                    <div class="bdp-field">
                        <label>Database connection name</label>
                        <input type="text" name="balenco_db_connection" value="{{ old('balenco_db_connection', $balencoDbConnection) }}" placeholder="mysql">
                        <small>Leave as-is if categories are managed manually in the store.</small>
                    </div>
                </div>

                <div class="bdp-note">
                    Category fetching is optional and disabled from this page. You can safely manage product categories directly inside the store.
                </div>
            </div>
        </div>

        <aside class="bdp-settings-stack">
            <div class="bdp-settings-card">
                <h3>AI SEO Prompt Settings</h3>
                <p>Admin-only defaults for AI product optimization prompts.</p>

                <div class="bdp-form-grid" style="margin-top:16px">
                    <div class="bdp-field">
                        <label>Short description words</label>
                        <input type="text" name="ai_short_words" value="{{ old('ai_short_words', $aiShortWords) }}">
                        <small>Used when building product short descriptions.</small>
                    </div>

                    <div class="bdp-field">
                        <label>Long description words</label>
                        <input type="text" name="ai_long_words" value="{{ old('ai_long_words', $aiLongWords) }}">
                        <small>Used when building full product content.</small>
                    </div>

                    <div class="bdp-field">
                        <label>Meta description characters</label>
                        <input type="text" name="ai_meta_chars" value="{{ old('ai_meta_chars', $aiMetaChars) }}">
                        <small>Recommended Google meta description length.</small>
                    </div>

                    <div class="bdp-field">
                        <label>Tags count</label>
                        <input type="number" name="ai_tags_count" value="{{ old('ai_tags_count', $aiTagsCount) }}" min="1" max="20">
                        <small>Default number of AI-generated product tags.</small>
                    </div>

                    <div class="bdp-field" style="grid-column:1/-1">
                        <label>Default buyer intent</label>
                        <select name="ai_default_buyer_intent">
                            @foreach(['Everyday wear', 'Party / event wear', 'Office wear', 'Casual fashion', 'Walking / travel', 'Summer wear', 'Dressy occasion'] as $intent)
                                <option value="{{ $intent }}" @selected($aiDefaultBuyerIntent === $intent)>{{ $intent }}</option>
                            @endforeach
                        </select>
                        <small>Used as the default buyer intent on the product edit AI tool.</small>
                    </div>
                </div>
            </div>

            <div class="bdp-settings-card">
                <h3>Optional Category Sync</h3>
                <p>This feature is optional and not required for your current workflow.</p>
                <div class="bdp-muted-box">
                    If categories are managed manually in Balenco/Shofy, you do not need to fetch categories here.
                </div>
            </div>
        </aside>
    </div>
</form>

{{-- BDP_SETTINGS_TABS_UI_START --}}
<style>
    .bdp-settings-tabs-shell{
        margin:18px 0 22px;
        background:#fff;
        border:1px solid #e5eaf3;
        border-radius:18px;
        padding:10px;
        box-shadow:0 14px 35px rgba(15,23,42,.06);
    }
    .bdp-settings-tabs{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        align-items:center;
    }
    .bdp-settings-tab{
        appearance:none;
        border:1px solid #e5eaf3;
        background:#f8fafc;
        color:#334155;
        border-radius:999px;
        padding:10px 16px;
        font-weight:800;
        font-size:13px;
        line-height:1;
        cursor:pointer;
        transition:all .16s ease;
    }
    .bdp-settings-tab:hover{
        background:#fff7ed;
        border-color:#fed7aa;
        color:#ea580c;
        transform:translateY(-1px);
    }
    .bdp-settings-tab.is-active{
        background:#0f172a;
        border-color:#0f172a;
        color:#fff;
        box-shadow:0 8px 18px rgba(15,23,42,.18);
    }
    .bdp-settings-tab-panel{
        display:none;
        animation:bdpSettingsFade .18s ease;
    }
    .bdp-settings-tab-panel.is-active{
        display:block;
    }
    .bdp-settings-tab-panel .bdp-card,
    .bdp-settings-tab-panel > .bdp-card{
        margin-bottom:18px;
    }
    .bdp-settings-tab-help{
        color:#64748b;
        font-size:13px;
        margin:8px 4px 0;
    }
    @keyframes bdpSettingsFade{
        from{opacity:0; transform:translateY(4px)}
        to{opacity:1; transform:translateY(0)}
    }
    @media (max-width: 900px){
        .bdp-settings-tabs-shell{position:sticky;top:8px;z-index:30}
        .bdp-settings-tab{flex:1 1 auto;text-align:center}
    }
</style>


{{-- BDP_SETTINGS_TABS_UI_END --}}







{{-- BDP_CONNECTED_STORE_TEST_PATCH_START --}}
<style>
    .bdp-test-store-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}
    .bdp-test-store-result{margin-top:10px;padding:11px 13px;border-radius:12px;font-size:13px;font-weight:700;display:none}
    .bdp-test-store-result.is-ok{display:block;background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}
    .bdp-test-store-result.is-error{display:block;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
    .bdp-btn-test-store{background:#0f172a!important;color:#fff!important;border-color:#0f172a!important;transition:all .18s ease}
    .bdp-btn-test-store:hover{background:#ff5a1f!important;border-color:#ff5a1f!important;color:#fff!important;transform:translateY(-1px)}
    .bdp-btn-test-store:active{transform:translateY(0);filter:brightness(.94)}
</style>

<!-- BDP removed risky settings JS block to prevent blank screen -->

{{-- BDP_CONNECTED_STORE_TEST_PATCH_END --}}



<!-- BDP_STORE_CONNECTION_METHODS_JS_START -->
<script>
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function () {
        var pushMethod = document.getElementById('bdp-store-push-method');
        var dbPanel = document.getElementById('bdp-direct-db-settings');
        var apiPanel = document.getElementById('bdp-api-settings');
        var testBtn = document.getElementById('bdp-test-store-connection');
        var saveBtn = document.getElementById('bdp-save-connected-store');
        var result = document.getElementById('bdp-store-test-result');
        var mainForm = document.querySelector('form[action*="/settings"]') || document.querySelector('form');

        function setResult(message, ok) {
            if (!result) return;
            result.textContent = message || '';
            result.className = ok ? 'bdp-input-help bdp-test-store-result is-ok' : 'bdp-input-help bdp-test-store-result is-error';
            result.style.display = message ? 'inline-flex' : 'none';
        }

        function toggleMethod() {
            var method = pushMethod ? pushMethod.value : 'database';
            if (dbPanel) dbPanel.style.display = method === 'api' ? 'none' : '';
            if (apiPanel) apiPanel.style.display = method === 'api' ? '' : 'none';
        }

        function appBaseUrl() {
            var action = mainForm ? (mainForm.getAttribute('action') || '') : '';
            if (action) {
                return action.replace(/\/settings\/?(?:.*)?$/, '');
            }
            return window.location.origin;
        }

        function csrfToken() {
            var tokenInput = document.querySelector('input[name="_token"]');
            if (tokenInput && tokenInput.value) return tokenInput.value;
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function collectFields(targetAction) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = targetAction;
            form.style.display = 'none';

            var token = csrfToken();
            if (token) {
                var csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = '_token';
                csrf.value = token;
                form.appendChild(csrf);
            }

            var added = {};
            document.querySelectorAll('.bdp-connected-store-card input[name], .bdp-connected-store-card select[name], .bdp-connected-store-card textarea[name]').forEach(function (field) {
                if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = field.name;
                input.value = field.value;
                form.appendChild(input);
                added[field.name] = true;
            });

            // Fall back to all matching connection fields if card selector changes later.
            ['store_push_method','store_db_host','store_db_port','store_db_database','store_db_username','store_db_password','store_db_prefix','store_api_endpoint','store_api_secret','connected_store_name','connected_store_domain','connected_store_api_url','connected_store_enabled'].forEach(function (name) {
                if (added[name]) return;
                var field = document.querySelector('[name="' + name + '"]');
                if (!field) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = field.value;
                form.appendChild(input);
            });

            return form;
        }

        function submitTo(path, btn, busyText) {
            if (!mainForm) {
                alert('Settings form was not found.');
                return;
            }
            // Use Laravel's authenticated, named POST routes.
            var target = path;
            if (btn) {
                btn.disabled = true;
                btn.dataset.originalText = btn.textContent;
                btn.textContent = busyText;
            }
            setResult('', true);
            var form = collectFields(target);
            document.body.appendChild(form);
            form.submit();
        }

        if (pushMethod) {
            pushMethod.addEventListener('change', toggleMethod);
            toggleMethod();
        }

        if (testBtn) {
            testBtn.addEventListener('click', function (event) {
                event.preventDefault();
                submitTo(@json(route('bdp.settings.connection.test')), testBtn, 'Testing...');
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function (event) {
                event.preventDefault();
                submitTo(@json(route('bdp.settings.store.save')), saveBtn, 'Saving...');
            });
        }
    });
})();
</script>
<!-- BDP_STORE_CONNECTION_METHODS_JS_END -->
<!-- BDP_SETTINGS_BLANK_SCREEN_SAFE_UNHIDE_END -->




<style id="bdp-settings-safe-visible-css">
    .bdp-settings-panel,
    [data-settings-panel],
    [data-bdp-settings-tab-panel],
    .bdp-settings-tab-panel,
    .bdp-card,
    .bdp-form-grid,
    form {
        visibility: visible !important;
        opacity: 1 !important;
    }
    .bdp-settings-panel,
    [data-settings-panel],
    [data-bdp-settings-tab-panel],
    .bdp-settings-tab-panel {
        display: block !important;
    }
</style>

@endsection
