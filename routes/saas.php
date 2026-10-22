<?php

use App\Http\Controllers\Saas\SaasAuthController;
use App\Http\Controllers\Saas\WorkspaceDashboardController;
use App\Http\Middleware\EnsureSaasEnabled;
use App\Http\Middleware\EnsureSaasWorkspaceMember;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public SaaS features can run unless SAAS_ENABLED is explicitly enabled.
// Signups remain off separately until billing + independent QA have passed.
Route::prefix('app')->middleware([EnsureSaasEnabled::class])->name('saas.')->group(function () {
    Route::get('/login', [SaasAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [SaasAuthController::class, 'login'])
        ->middleware('throttle:6,1')->name('login.submit');

    Route::get('/register', [SaasAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [SaasAuthController::class, 'register'])
        ->middleware('throttle:4,1')->name('register.submit');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [SaasAuthController::class, 'logout'])->name('logout');

        Route::get('/email/verify', fn () => view('saas.auth.verify-email'))
            ->name('verification.notice');
        Route::post('/email/verification-notification', function (Request $request) {
            $request->user()->sendEmailVerificationNotification();

            return back()->with('status', 'verification-link-sent');
        })->middleware('throttle:6,1')->name('verification.send');

        Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
            $request->fulfill();

            return redirect()->route('saas.home');
        })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

        Route::middleware('verified')->group(function () {
            Route::get('/', [SaasAuthController::class, 'home'])->name('home');
            Route::get('/workspaces/{workspace:public_id}', [WorkspaceDashboardController::class, 'show'])
                ->middleware(EnsureSaasWorkspaceMember::class)
                ->name('workspace.dashboard');
        });
    });
});
