<?php

use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\TenantController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Compat\AdminBillingController as CompatAdminBillingController;
use App\Http\Controllers\Api\V1\Compat\AdminDashboardController as CompatAdminDashboardController;
use App\Http\Controllers\Api\V1\Compat\AdminOrgController as CompatAdminOrgController;
use App\Http\Controllers\Api\V1\Compat\AdminRatingController;
use App\Http\Controllers\Api\V1\Compat\AdminUserController as CompatAdminUserController;
use App\Http\Controllers\Api\V1\Compat\CompetitionAdminController;
use App\Http\Controllers\Api\V1\Compat\AdminEmailSettingsController;
use App\Http\Controllers\Api\V1\Compat\PlatformController;
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
    Route::get('/dashboard', [CompatAdminDashboardController::class, 'index'])->name('dashboard');

    // Group 21: disputed match results and result reversal.
    Route::get('/disputes', [CompetitionAdminController::class, 'disputes'])->name('disputes.index');
    Route::post('/matches/{match}/resolve', [CompetitionAdminController::class, 'resolve'])->name('matches.resolve');
    Route::post('/matches/{match}/reverse', [CompetitionAdminController::class, 'reverse'])->name('matches.reverse');

    // Group 24: rating list, manual corrections and editable ELO rules.
    Route::get('/ratings', [AdminRatingController::class, 'index'])->name('ratings.index');
    Route::get('/ratings/adjustments', [AdminRatingController::class, 'adjustments'])->name('ratings.adjustments');
    Route::post('/ratings/adjust', [AdminRatingController::class, 'adjust'])->name('ratings.adjust');
    Route::get('/rating-rules', [AdminRatingController::class, 'rules'])->name('rating_rules.show');
    Route::put('/rating-rules', [AdminRatingController::class, 'updateRules'])->name('rating_rules.update');

    // Group 17: the Super Admin portal's venues, clubs and user accounts.
    Route::get('/venues', [CompatAdminOrgController::class, 'venues'])->name('venues.index');
    Route::get('/venues/{venue}', [CompatAdminOrgController::class, 'venue'])->name('venues.show');
    Route::post('/venues/{venue}/decision', [CompatAdminOrgController::class, 'decideVenue'])->name('venues.decision');
    Route::get('/clubs', [CompatAdminOrgController::class, 'clubs'])->name('clubs.index');
    Route::get('/clubs/{club}', [CompatAdminOrgController::class, 'club'])->name('clubs.show');
    Route::post('/clubs/{club}/decision', [CompatAdminOrgController::class, 'decideClub'])->name('clubs.decision');
    // Group 18: plans, venue subscriptions, invoices and revenue.
    Route::get('/plans', [CompatAdminBillingController::class, 'plans'])->name('plans.index');
    Route::post('/plans', [CompatAdminBillingController::class, 'storePlan'])->name('plans.store');
    Route::patch('/plans/{code}', [CompatAdminBillingController::class, 'updatePlan'])->name('plans.update');
    Route::get('/subscriptions', [CompatAdminBillingController::class, 'subscriptions'])->name('billing.subscriptions.index');
    Route::get('/subscriptions/{subscription}', [CompatAdminBillingController::class, 'subscription'])->name('billing.subscriptions.show');
    Route::post('/subscriptions/{subscription}/extend-trial', [CompatAdminBillingController::class, 'extendTrial'])->name('billing.subscriptions.extend_trial');
    Route::post('/subscriptions/{subscription}/change-plan', [CompatAdminBillingController::class, 'changePlan'])->name('billing.subscriptions.change_plan');
    Route::get('/invoices', [CompatAdminBillingController::class, 'invoices'])->name('billing.invoices.index');
    Route::post('/invoices/{invoice}/mark-paid', [CompatAdminBillingController::class, 'markPaid'])->name('billing.invoices.mark_paid');
    Route::post('/invoices/{invoice}/void', [CompatAdminBillingController::class, 'voidInvoice'])->name('billing.invoices.void');
    Route::get('/revenue', [CompatAdminBillingController::class, 'revenue'])->name('billing.revenue');

    Route::get('/users', [CompatAdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [CompatAdminUserController::class, 'show'])->name('users.show');
    Route::patch('/users/{user}/status', [CompatAdminUserController::class, 'updateStatus'])->name('users.status');
    Route::post('/users/{user}/password-reset', [CompatAdminUserController::class, 'sendPasswordReset'])->name('users.password_reset');

    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('throttle:resource-creation')->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::patch('/tenants/{tenant}/status', [TenantController::class, 'updateStatus'])->name('tenants.status');

    Route::get('/subscription-plans', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan'])->middleware('throttle:resource-creation')->name('subscriptions.plans.store');

    Route::get('/analytics', [AnalyticsController::class, 'analytics'])->name('analytics');

    // Group 19: the audit log, announcements and platform settings.
    Route::get('/audit-logs', [PlatformController::class, 'auditLogs'])->name('audit_logs');
    Route::get('/announcements', [PlatformController::class, 'adminAnnouncements'])->name('announcements.index');
    Route::post('/announcements', [PlatformController::class, 'storeAnnouncement'])->name('announcements.store');
    Route::patch('/announcements/{announcement}', [PlatformController::class, 'updateAnnouncement'])->name('announcements.update');
    Route::post('/announcements/{announcement}/end', [PlatformController::class, 'endAnnouncement'])->name('announcements.end');
    Route::get('/settings', [PlatformController::class, 'adminSettings'])->name('settings');
    Route::put('/settings', [PlatformController::class, 'updateSettings'])->name('settings.update');
    Route::get('/email-settings', [AdminEmailSettingsController::class, 'show'])->name('email_settings.show');
    Route::put('/email-settings', [AdminEmailSettingsController::class, 'update'])->name('email_settings.update');
    Route::post('/email-settings/test', [AdminEmailSettingsController::class, 'sendTest'])->name('email_settings.test');
});
