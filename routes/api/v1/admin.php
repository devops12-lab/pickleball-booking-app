<?php

use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\TenantController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Admin Authentication Routes (/api/v1/admin/auth/*)
|--------------------------------------------------------------------------
| Strictly restricted to email & password. NO Google login/registration.
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password')->name('password.forgot');
    Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset');

    Route::middleware(['auth:sanctum', 'account.active', 'role:system-admin', 'throttle:api'])->group(function (): void {
        Route::get('/me', [AdminAuthController::class, 'me'])->name('me');
        Route::post('/change-password', [AdminAuthController::class, 'changePassword'])->middleware('throttle:sensitive')->name('password.change');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('/tokens/revoke-all', [AdminAuthController::class, 'revokeAll'])->name('tokens.revoke_all');
    });
});

/*
|--------------------------------------------------------------------------
| System Admin Business APIs (/api/v1/admin/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.active', 'role:system-admin', 'throttle:api'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('throttle:resource-creation')->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('/tenants/{tenant}/status', [TenantController::class, 'updateStatus'])->name('tenants.status');

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan'])->middleware('throttle:resource-creation')->name('subscriptions.plans.store');

    Route::get('/analytics', [AnalyticsController::class, 'analytics'])->name('analytics');
    Route::get('/audit-logs', [AnalyticsController::class, 'auditLogs'])->name('audit_logs');
    Route::get('/settings', [AnalyticsController::class, 'settings'])->name('settings');
});
