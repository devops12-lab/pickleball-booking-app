<?php

use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\AnnouncementController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DatabaseBackupController;
use App\Http\Controllers\Api\V1\Admin\DatabaseBackupLogController;
use App\Http\Controllers\Api\V1\Admin\DatabaseBackupScheduleController;
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

    /*
    |--------------------------------------------------------------------------
    | Database Backup & Restore
    |--------------------------------------------------------------------------
    */
    Route::prefix('database-backups')->name('database_backups.')->group(function (): void {
        Route::get('/', [DatabaseBackupController::class, 'index'])
            ->middleware('permission:database_backups.view')
            ->name('index');

        Route::post('/', [DatabaseBackupController::class, 'store'])
            ->middleware(['permission:database_backups.create', 'throttle:resource-creation'])
            ->name('store');

        Route::post('/upload', [DatabaseBackupController::class, 'upload'])
            ->middleware(['permission:database_backups.create', 'throttle:resource-creation'])
            ->name('upload');

        Route::post('/restore-upload', [DatabaseBackupController::class, 'restoreUploaded'])
            ->middleware(['permission:database_backups.create', 'permission:database_backups.restore', 'throttle:sensitive'])
            ->name('restore_upload');

        Route::get('/monitoring', [DatabaseBackupController::class, 'monitoring'])
            ->middleware('permission:database_backups.view')
            ->name('monitoring');

        Route::get('/operations', [DatabaseBackupLogController::class, 'restores'])
            ->middleware('permission:database_backups.view')
            ->name('operations.index');

        Route::get('/operations/{restoreLog}', [DatabaseBackupLogController::class, 'showRestore'])
            ->middleware('permission:database_backups.view')
            ->whereNumber('restoreLog')
            ->name('operations.show');

        Route::get('/audit-logs', [DatabaseBackupLogController::class, 'auditLogs'])
            ->middleware('permission:database_backups.view')
            ->name('audit_logs');

        Route::get('/{backup}', [DatabaseBackupController::class, 'show'])
            ->middleware('permission:database_backups.view')
            ->whereNumber('backup')
            ->name('show');

        Route::get('/{backup}/download', [DatabaseBackupController::class, 'download'])
            ->middleware(['permission:database_backups.view', 'throttle:sensitive'])
            ->whereNumber('backup')
            ->name('download');

        Route::post('/{backup}/restore', [DatabaseBackupController::class, 'restore'])
            ->middleware(['permission:database_backups.restore', 'throttle:sensitive'])
            ->whereNumber('backup')
            ->name('restore');

        Route::delete('/{backup}', [DatabaseBackupController::class, 'destroy'])
            ->middleware('permission:database_backups.delete')
            ->whereNumber('backup')
            ->name('destroy');
    });

    Route::prefix('backup-schedules')->name('database_backups.schedules.')->group(function (): void {
        Route::get('/', [DatabaseBackupScheduleController::class, 'index'])
            ->middleware('permission:database_backups.manage_schedule')
            ->name('index');

        Route::patch('/{schedule}', [DatabaseBackupScheduleController::class, 'update'])
            ->middleware('permission:database_backups.manage_schedule')
            ->whereNumber('schedule')
            ->name('update');

        Route::post('/{schedule}/run', [DatabaseBackupScheduleController::class, 'run'])
            ->middleware(['permission:database_backups.create', 'throttle:resource-creation'])
            ->whereNumber('schedule')
            ->name('run');
    });

    /*
    |--------------------------------------------------------------------------
    | Announcements (platform-wide)
    |--------------------------------------------------------------------------
    */
    Route::prefix('announcements')->name('announcements.')->group(function (): void {
        Route::get('/', [AnnouncementController::class, 'index'])
            ->middleware('permission:announcements.view')
            ->name('index');

        Route::post('/', [AnnouncementController::class, 'store'])
            ->middleware(['permission:announcements.create', 'throttle:resource-creation'])
            ->name('store');

        Route::get('/{announcement}', [AnnouncementController::class, 'show'])
            ->middleware('permission:announcements.view')
            ->whereNumber('announcement')
            ->name('show');

        Route::patch('/{announcement}', [AnnouncementController::class, 'update'])
            ->middleware('permission:announcements.update')
            ->whereNumber('announcement')
            ->name('update');

        Route::delete('/{announcement}', [AnnouncementController::class, 'destroy'])
            ->middleware('permission:announcements.delete')
            ->whereNumber('announcement')
            ->name('destroy');

        Route::post('/{announcement}/publish', [AnnouncementController::class, 'publish'])
            ->middleware('permission:announcements.publish')
            ->whereNumber('announcement')
            ->name('publish');

        Route::post('/{announcement}/archive', [AnnouncementController::class, 'archive'])
            ->middleware('permission:announcements.publish')
            ->whereNumber('announcement')
            ->name('archive');
    });
});
