<?php

use App\Http\Controllers\Api\V1\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Staff\BookingController;
use App\Http\Controllers\Api\V1\Staff\CheckInController;
use App\Http\Controllers\Api\V1\Staff\DashboardController;
use App\Http\Controllers\Api\V1\Staff\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facility Staff Authentication Routes (/api/v1/staff/auth/*)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/login', [StaffAuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('/google', [StaffAuthController::class, 'google'])->middleware('throttle:login')->name('google');
    Route::post('/accept-invitation', [StaffAuthController::class, 'acceptInvitation'])->middleware('throttle:sensitive')->name('accept_invitation');
    Route::post('/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password')->name('password.forgot');
    Route::post('/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset');

    Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function (): void {
        Route::get('/me', [StaffAuthController::class, 'me'])->name('me');
        Route::post('/change-password', [StaffAuthController::class, 'changePassword'])->middleware('throttle:sensitive')->name('password.change');
        Route::post('/logout', [StaffAuthController::class, 'logout'])->name('logout');
        Route::post('/tokens/revoke-all', [StaffAuthController::class, 'revokeAll'])->name('tokens.revoke_all');
    });
});

/*
|--------------------------------------------------------------------------
| Facility Staff Business APIs (/api/v1/staff/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.active', 'tenant.context', 'tenant.access', 'role:staff', 'throttle:api'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus'])->name('bookings.status');

    Route::get('/payments/pending', [PaymentController::class, 'pending'])->name('payments.pending');
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

    Route::post('/bookings/{booking}/check-in', [CheckInController::class, 'checkIn'])->name('bookings.check_in');
});
