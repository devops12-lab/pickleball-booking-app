<?php

use App\Http\Controllers\Api\V1\Auth\OwnerAuthController;
use App\Http\Controllers\Api\V1\Owners\BookingController;
use App\Http\Controllers\Api\V1\Owners\CourtController;
use App\Http\Controllers\Api\V1\Owners\DashboardController;
use App\Http\Controllers\Api\V1\Owners\FacilityController;
use App\Http\Controllers\Api\V1\Owners\PaymentController;
use App\Http\Controllers\Api\V1\Owners\ReportController;
use App\Http\Controllers\Api\V1\Owners\StaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Facility Owner Authentication Routes (/api/v1/owners/auth/*)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [OwnerAuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('/login', [OwnerAuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('/google', [OwnerAuthController::class, 'google'])->middleware('throttle:login')->name('google');
    Route::post('/forgot-password', [OwnerAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password')->name('password.forgot');
    Route::post('/reset-password', [OwnerAuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset');
    Route::post('/verify-email', [OwnerAuthController::class, 'verifyEmail'])->middleware('throttle:email-verification')->name('verify_email');

    Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function (): void {
        Route::get('/me', [OwnerAuthController::class, 'me'])->name('me');
        Route::post('/change-password', [OwnerAuthController::class, 'changePassword'])->middleware('throttle:sensitive')->name('password.change');
        Route::post('/logout', [OwnerAuthController::class, 'logout'])->name('logout');
        Route::post('/tokens/revoke-all', [OwnerAuthController::class, 'revokeAll'])->name('tokens.revoke_all');
    });
});

/*
|--------------------------------------------------------------------------
| Facility Owner Business APIs (/api/v1/owners/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.active', 'tenant.context', 'tenant.access', 'role:owner', 'throttle:api'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities.index');
    Route::post('/facilities', [FacilityController::class, 'store'])->middleware('throttle:resource-creation')->name('facilities.store');
    Route::get('/facilities/{facility}', [FacilityController::class, 'show'])->name('facilities.show');
    Route::patch('/facilities/{facility}', [FacilityController::class, 'update'])->name('facilities.update');
    Route::delete('/facilities/{facility}', [FacilityController::class, 'destroy'])->name('facilities.destroy');

    Route::get('/courts', [CourtController::class, 'index'])->name('courts.index');
    Route::post('/courts', [CourtController::class, 'store'])->middleware('throttle:resource-creation')->name('courts.store');
    Route::get('/courts/{court}', [CourtController::class, 'show'])->name('courts.show');
    Route::patch('/courts/{court}', [CourtController::class, 'update'])->name('courts.update');
    Route::delete('/courts/{court}', [CourtController::class, 'destroy'])->name('courts.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::delete('/staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
});
