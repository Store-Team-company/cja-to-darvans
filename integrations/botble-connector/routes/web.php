<?php

use Botble\Base\Facades\BaseHelper;
use Botble\DatapilotConnector\Http\Controllers\SettingsController;
use Botble\DatapilotConnector\Http\Controllers\DraftInboxController;
use Illuminate\Support\Facades\Route;

// Read-only settings are visible to authorized Botble administrators ONLY.
// No public pairing, API keys or product writes exist in this phase.
Route::group(['middleware' => ['web', 'core']], function (): void {
    Route::group(['prefix' => BaseHelper::getAdminPrefix(), 'middleware' => 'auth'], function (): void {
        Route::get('data-pilot/connector', [
            'as' => 'datapilot.connector.settings',
            'uses' => SettingsController::class . '@index',
            'permission' => 'datapilot-connector.settings',
        ]);
        Route::post('data-pilot/connector/pair', [
            'as' => 'datapilot.connector.pair',
            'uses' => SettingsController::class . '@pair',
            'permission' => 'datapilot-connector.settings',
        ])->middleware('throttle:5,1');
        Route::post('data-pilot/connector/revoke', [
            'as' => 'datapilot.connector.revoke',
            'uses' => SettingsController::class . '@revoke',
            'permission' => 'datapilot-connector.settings',
        ])->middleware('throttle:5,1');
    });
});


// Incoming SaaS server-to-server messages are verified with a per-store
// HMAC. This endpoint does NOT use browser session authentication or CSRF.
// The inbox can only stage drafts; it cannot modify the Botble catalog.
Route::post('api/datapilot/v1/drafts', [DraftInboxController::class, 'stage'])
    ->middleware('throttle:40,1')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('datapilot.connector.api.drafts');
