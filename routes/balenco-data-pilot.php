<?php

use App\Http\Controllers\BalencoDataPilotController;
use App\Http\Controllers\BalencoCjaWooApiController;
use App\Http\Controllers\BdpCategoryMappingController;
use App\Http\Controllers\BdpProfileController;
use App\Http\Controllers\BdpSettingsController;
use App\Http\Controllers\Saas\SaasCustomerAdminController;
use App\Http\Controllers\Saas\SaasAdminPlanGrantController;
use App\Http\Controllers\BdpAiPromptSettingsController;
use App\Http\Middleware\BalencoDataPilotAuth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [BalencoDataPilotController::class, 'loginForm'])->name('bdp.login.form');
Route::post('/login', [BalencoDataPilotController::class, 'login'])->name('bdp.login');
Route::post('/logout', [BalencoDataPilotController::class, 'logout'])->name('bdp.logout');



// WooCommerce app authentication endpoint used by CJA Default connection.
// This simulates WooCommerce's /wc-auth/v1/authorize flow and returns API keys to CJA.
Route::match(['GET', 'POST'], '/wc-auth/v1/authorize', [BalencoCjaWooApiController::class, 'wcAuthorize'])->name('bdp.cja.woo.auth.authorize');
Route::match(['GET', 'POST'], '/wc-auth/v1/authorize/', [BalencoCjaWooApiController::class, 'wcAuthorize'])->name('bdp.cja.woo.auth.authorize.slash');
Route::match(['GET', 'POST'], '/wc-auth/v1/authorize/approve', [BalencoCjaWooApiController::class, 'wcAuthorizeApprove'])->name('bdp.cja.woo.auth.approve');

// WooCommerce-compatible API listener for CJA one-click listing.
// CJA store URL: https://your-data-pilot-domain.com
// Supports common WooCommerce REST paths used by CJA and other importers.
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/wp-json/wc/v3/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.api.v3');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/wp-json/wc/v2/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.api.v2');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/wp-json/wc/v1/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.api.v1');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/wc-api/v3/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.legacy.v3');


// CJA fallback routes: if CJA accidentally stores /login as the base path after an auth redirect,
// these aliases still allow the WooCommerce auth/API flow to work.
Route::match(['GET', 'POST'], '/login/wc-auth/v1/authorize', [BalencoCjaWooApiController::class, 'wcAuthorize'])->name('bdp.cja.woo.auth.authorize.login_alias');
Route::match(['GET', 'POST'], '/login/wc-auth/v1/authorize/', [BalencoCjaWooApiController::class, 'wcAuthorize'])->name('bdp.cja.woo.auth.authorize.login_alias_slash');
Route::match(['GET', 'POST'], '/login/wc-auth/v1/authorize/approve', [BalencoCjaWooApiController::class, 'wcAuthorizeApprove'])->name('bdp.cja.woo.auth.approve.login_alias');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/login/wp-json/wc/v3/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.api.v3.login_alias');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/login/wp-json/wc/v2/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.api.v2.login_alias');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/login/wp-json/wc/v1/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.api.v1.login_alias');
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/login/wc-api/v3/{endpoint?}', [BalencoCjaWooApiController::class, 'handle'])
    ->where('endpoint', '.*')
    ->name('bdp.cja.woo.legacy.v3.login_alias');

Route::middleware([BalencoDataPilotAuth::class])->name('bdp.')->group(function () {
    Route::get('/', [BalencoDataPilotController::class, 'index'])->name('index');
    Route::get('/products', [BalencoDataPilotController::class, 'products'])->name('products');
    Route::get('/import', [BalencoDataPilotController::class, 'importForm'])->name('import.form');
    Route::get('/cj-settings', [BalencoDataPilotController::class, 'cjSettings'])->name('cj.settings');
    Route::post('/cj-settings', [BalencoDataPilotController::class, 'saveCjSettings'])->name('cj.settings.save');
    Route::post('/cj-settings/token', [BalencoDataPilotController::class, 'getCjToken'])->name('cj.token');
    Route::post('/cj-settings/refresh', [BalencoDataPilotController::class, 'refreshCjToken'])->name('cj.token.refresh');
    Route::post('/cj-settings/test', [BalencoDataPilotController::class, 'testCjConnection'])->name('cj.test');
    Route::get('/cj-import', [BalencoDataPilotController::class, 'cjImportForm'])->name('cj.import');
    Route::post('/cj-import', [BalencoDataPilotController::class, 'importFromCj'])->name('cj.import.product');
    Route::get('/api-logs', [BalencoDataPilotController::class, 'apiLogs'])->name('api.logs');
    Route::post('/api-logs/clear', [BalencoDataPilotController::class, 'clearApiLogs'])->name('api.logs.clear');
    Route::get('/category-mapping', [BalencoDataPilotController::class, 'categoryMapping'])->name('categories.mapping');
    Route::post('/category-mapping', [BalencoDataPilotController::class, 'saveCategoryMapping'])->name('categories.mapping.save');
    Route::post('/bulk-assign-category', [BalencoDataPilotController::class, 'bulkAssignCategory'])->name('categories.bulk.assign');
    Route::post('/import', [BalencoDataPilotController::class, 'import'])->name('import');
    Route::get('/products/{product}/edit', [BalencoDataPilotController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [BalencoDataPilotController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/refresh-cj-inventory-shipping', [BalencoDataPilotController::class, 'refreshCjInventoryShipping'])->name('products.refresh.cj');
    Route::post('/export', [BalencoDataPilotController::class, 'export'])->name('export');
    Route::post('/bulk-edit', [BalencoDataPilotController::class, 'bulkEdit'])->name('bulk.edit');
    Route::post('/bulk-update', [BalencoDataPilotController::class, 'bulkUpdate'])->name('bulk.update');
    Route::post('/bulk-pricing', [BalencoDataPilotController::class, 'bulkPricing'])->name('bulk.pricing');
    Route::post('/push-to-shofy', [BalencoDataPilotController::class, 'pushToShofy'])->name('push');

    // Existing views and controller methods rely on these named routes.
    // Keep all mutating operations POST-only and behind dashboard authentication.
    Route::get('/saas-customers', [SaasCustomerAdminController::class, 'index'])
        ->middleware(\App\Http\Middleware\EnsureSaasEnabled::class)->name('saas.customers');
    Route::post('/saas-customers/{customer}/approve', [SaasCustomerAdminController::class, 'approve'])
        ->middleware([\App\Http\Middleware\EnsureSaasEnabled::class, 'throttle:10,1'])
        ->name('saas.customers.approve');

    // Complimentary workspace access never writes to Stripe billing records.
    Route::get('/saas-workspaces', [SaasAdminPlanGrantController::class, 'index'])
        ->middleware(\App\Http\Middleware\EnsureSaasEnabled::class)->name('saas.workspaces');
    Route::post('/saas-workspaces/{workspace:public_id}/grant', [SaasAdminPlanGrantController::class, 'grant'])
        ->middleware([\App\Http\Middleware\EnsureSaasEnabled::class, 'throttle:5,1'])
        ->name('saas.workspaces.grant');
    Route::post('/saas-workspaces/{workspace:public_id}/revoke', [SaasAdminPlanGrantController::class, 'revoke'])
        ->middleware([\App\Http\Middleware\EnsureSaasEnabled::class, 'throttle:5,1'])
        ->name('saas.workspaces.revoke');

    Route::get('/profile', [BdpProfileController::class, 'index'])->name('profile');
    Route::post('/profile', [BdpProfileController::class, 'update'])->name('profile.update');

    Route::get('/settings', [BdpSettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [BdpSettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/ai-prompts', [BdpAiPromptSettingsController::class, 'update'])->name('settings.ai.prompts.update');
    Route::post('/settings/fetch-categories', [BdpSettingsController::class, 'fetchBalencoCategories'])->name('settings.categories.fetch');
    Route::post('/settings/test-connected-store', [BdpSettingsController::class, 'testConnectedStore'])->name('settings.store.test.connected');
    Route::post('/settings/test-store-connection', [BdpSettingsController::class, 'testStoreConnection'])->name('settings.store.test');
    Route::post('/settings/test-connection', [BdpSettingsController::class, 'testConnection'])->name('settings.connection.test');
    Route::post('/settings/connected-store', [BdpSettingsController::class, 'saveConnectedStore'])->name('settings.store.save');

    Route::post('/category-mapping/store', [BdpCategoryMappingController::class, 'store'])->name('categories.mapping.store');
    Route::post('/category-mapping/auto', [BdpCategoryMappingController::class, 'autoMap'])->name('categories.mapping.auto');
    Route::post('/category-mapping/fetch', [BdpCategoryMappingController::class, 'fetchBalencoCategories'])->name('categories.mapping.fetch');

    Route::post('/cj-import/bulk', [BalencoDataPilotController::class, 'importMultipleFromCj'])->name('cj.import.bulk');
    Route::post('/products/{product}/reviews/fetch-cj', [BalencoDataPilotController::class, 'fetchCjReviews'])->name('products.reviews.fetch.cj');

    Route::post('/bulk-optimization/export', [BalencoDataPilotController::class, 'exportBulkOptimizationCsv'])->name('bulk.optimization.export');
    Route::post('/bulk-optimization/import', [BalencoDataPilotController::class, 'importBulkOptimizationCsv'])->name('bulk.optimization.import');

    Route::post('/products/archive', [BalencoDataPilotController::class, 'archiveProducts'])->name('products.archive');
    Route::post('/products/restore', [BalencoDataPilotController::class, 'restoreProducts'])->name('products.restore');
    Route::post('/products/labels/assign', [BalencoDataPilotController::class, 'assignProductLabel'])->name('products.labels.assign');
    Route::post('/products/labels/remove', [BalencoDataPilotController::class, 'removeProductLabel'])->name('products.labels.remove');
    Route::get('/products/{product}/images.zip', [BalencoDataPilotController::class, 'downloadProductImagesZip'])->name('products.images.zip');

    Route::get('/sync-queue', [BalencoDataPilotController::class, 'syncQueue'])->name('sync.queue.index');
    Route::post('/sync-queue/enqueue', [BalencoDataPilotController::class, 'enqueueSyncQueue'])->name('sync.queue.enqueue');

});

// Old URL support. If you open /balenco-data-pilot, it redirects to root.
Route::redirect('/balenco-data-pilot', '/');
