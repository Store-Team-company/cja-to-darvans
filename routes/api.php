<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Machine-to-machine only: raw body HMAC verified inside the controller.
// No browser sessions or CSRF tokens are involved in Stripe webhooks.
Route::post('/saas/stripe/webhook', \App\Http\Controllers\Saas\SaasBillingWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('saas.stripe.webhook');


// The merchant authorizes this one-time 256-bit secret in their own
// workspace. Botble admin sends it from the plugin over HTTPS; requests
// never use shared database credentials or an unauthenticated browser cookie.
Route::post('/saas/botble/pair/complete', [\App\Http\Controllers\Saas\SaasBotblePairingController::class, 'complete'])
    ->middleware('throttle:15,1')->name('saas.botble.pair.complete');
