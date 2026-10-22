<?php

use Botble\Base\Facades\BaseHelper;
use Botble\DatapilotConnector\Http\Controllers\SettingsController;
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
    });
});
