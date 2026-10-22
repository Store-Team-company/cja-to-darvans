<?php

use App\Http\Controllers\Saas\SaasAuthController;
use App\Http\Controllers\Saas\SaasBillingController;
use App\Http\Controllers\Saas\SaasConnectionController;
use App\Http\Middleware\EnsureSaasEntitlement;
use App\Http\Controllers\Saas\SaasPublicController;
use App\Http\Controllers\Saas\SaasPortalController;
use App\Http\Controllers\Saas\WorkspaceDashboardController;
use App\Http\Middleware\EnsureSaasEnabled;
use App\Http\Middleware\EnsureSaasWorkspaceMember;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public SaaS features can run unless SAAS_ENABLED is explicitly enabled.
// Signups remain off separately until billing + independent QA have passed.
Route::middleware([EnsureSaasEnabled::class])->group(function () {
    Route::get('/platform', [SaasPublicController::class, 'home'])->name('saas.landing');
    Route::get('/pricing', [SaasPublicController::class, 'pricing'])->name('saas.pricing');
});

Route::prefix('app')->middleware([EnsureSaasEnabled::class])->name('saas.')->group(function () {
    Route::get('/login', [SaasAuthController::class, 'showLogin'])->name('login');
    Route::get('/forgot-password', [SaasAuthController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [SaasAuthController::class, 'sendReset'])->middleware('throttle:5,1')->name('password.email');
    Route::post('/reset-password', [SaasAuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
    Route::post('/login', [SaasAuthController::class, 'login'])
        ->middleware('throttle:6,1')->name('login.submit');

    Route::get('/register', [SaasAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [SaasAuthController::class, 'register'])
        ->middleware('throttle:4,1')->name('register.submit');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [SaasAuthController::class, 'logout'])->name('logout');

        Route::post('/email/verification-notification', function (Request $request) {
            $request->user()->sendEmailVerificationNotification();

            return back()->with('status', 'verification-link-sent');
        })->middleware('throttle:6,1')->name('verification.send');

        Route::middleware('verified')->group(function () {
            Route::get('/', [SaasAuthController::class, 'home'])->name('home');
            Route::prefix('workspaces/{workspace:public_id}')
                ->middleware(EnsureSaasWorkspaceMember::class)
                ->name('workspace.')->group(function () {
                    Route::get('/', [WorkspaceDashboardController::class, 'show'])->name('dashboard');
                    Route::get('/products', [SaasPortalController::class, 'products'])->name('products');
                    Route::get('/connections', [SaasPortalController::class, 'connections'])->name('connections');
                    Route::post('/connections/cj', [SaasConnectionController::class, 'saveCj'])
                        ->middleware([EnsureSaasEntitlement::class.':connect_cj', 'throttle:6,1'])
                        ->name('connections.cj.save');
                    Route::delete('/connections/cj', [SaasConnectionController::class, 'removeCj'])
                        ->middleware('throttle:6,1')->name('connections.cj.remove');
                    Route::post('/connections/botble', [SaasConnectionController::class, 'addStore'])
                        ->middleware([EnsureSaasEntitlement::class.':manage_connection', 'throttle:6,1'])
                        ->name('connections.botble.add');
                    Route::delete('/connections/botble/{store}', [SaasConnectionController::class, 'removeStore'])
                        ->middleware('throttle:6,1')->name('connections.botble.remove');
                    Route::get('/team', [SaasPortalController::class, 'team'])->name('team');
                    Route::get('/settings', [SaasPortalController::class, 'settings'])->name('settings');
                    Route::patch('/settings', [SaasPortalController::class, 'updateSettings'])
                        ->middleware('throttle:15,1')->name('settings.update');
                    Route::get('/billing', [SaasBillingController::class, 'show'])->name('billing');
                    Route::post('/billing/checkout', [SaasBillingController::class, 'checkout'])
                        ->middleware('throttle:6,1')->name('billing.checkout');
                    Route::post('/billing/cancel-checkout', [SaasBillingController::class, 'abandonCheckout'])
                        ->middleware('throttle:6,1')->name('billing.cancel-checkout');
                    Route::post('/billing/portal', [SaasBillingController::class, 'portal'])
                        ->middleware('throttle:6,1')->name('billing.portal');
                });
        });
    });
});

/*
 * Laravel's verified middleware and VerifyEmail notification use the
 * global names verification.notice and verification.verify. Keep them
 * separate from the saas.* group so signed email URLs resolve properly.
 */
Route::middleware([EnsureSaasEnabled::class, 'auth'])->group(function () {
    Route::get('/app/email/verify', fn () => view('saas.auth.verify-email'))
        ->name('verification.notice');

    Route::get('/app/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('saas.home');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
});

Route::middleware([EnsureSaasEnabled::class, 'guest'])->group(function () {
    // Laravel password-reset notifications require the standard name.
    Route::get('/app/reset-password/{token}', [SaasAuthController::class, 'resetForm'])
        ->name('password.reset');
});
