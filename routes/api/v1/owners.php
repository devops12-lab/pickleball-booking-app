<?php

use App\Http\Controllers\Api\V1\Auth\OwnerAuthController;
use App\Http\Controllers\Api\V1\Owners\BookingController;
use App\Http\Controllers\Api\V1\Owners\CourtController;
use App\Http\Controllers\Api\V1\Owners\CourtFrontController;
use App\Http\Controllers\Api\V1\Owners\DashboardController;
use App\Http\Controllers\Api\V1\Owners\FacilityController;
use App\Http\Controllers\Api\V1\Owners\PaymentController;
use App\Http\Controllers\Api\V1\Owners\PaymentMethodController;
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

    // Court gallery images (1 to 5 per court).
    Route::post('/courts/{court}/images', [CourtController::class, 'addImages'])->middleware('throttle:resource-creation')->name('courts.images.add');
    Route::post('/courts/{court}/images/reorder', [CourtController::class, 'reorderImages'])->name('courts.images.reorder');
    Route::delete('/courts/{court}/images/{image}', [CourtController::class, 'deleteImage'])->name('courts.images.delete');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::delete('/staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

    /*
    |--------------------------------------------------------------------------
    | Facility Payment Methods (/api/v1/owners/payment-methods)
    |--------------------------------------------------------------------------
    */
    Route::prefix('payment-methods')->name('payment_methods.')->group(function (): void {
        Route::get('/', [PaymentMethodController::class, 'index'])->middleware('permission:payment_methods.manage')->name('index');
        Route::post('/', [PaymentMethodController::class, 'store'])->middleware(['permission:payment_methods.manage', 'throttle:resource-creation'])->name('store');
        Route::get('/{paymentMethod}', [PaymentMethodController::class, 'show'])->middleware('permission:payment_methods.manage')->whereNumber('paymentMethod')->name('show');
        Route::patch('/{paymentMethod}', [PaymentMethodController::class, 'update'])->middleware('permission:payment_methods.manage')->whereNumber('paymentMethod')->name('update');
        Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->middleware('permission:payment_methods.manage')->whereNumber('paymentMethod')->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Court Front / Store Front (/api/v1/owners/court-front)
    |--------------------------------------------------------------------------
    */
    Route::prefix('court-front')->name('court_front.')->group(function (): void {
        Route::get('/', [CourtFrontController::class, 'show'])->middleware('permission:court_front.manage')->name('show');
        Route::patch('/', [CourtFrontController::class, 'update'])->middleware('permission:court_front.manage')->name('update');
        Route::post('/logo', [CourtFrontController::class, 'uploadLogo'])->middleware(['permission:court_front.manage', 'throttle:resource-creation'])->name('logo');
        Route::post('/cover', [CourtFrontController::class, 'uploadCover'])->middleware(['permission:court_front.manage', 'throttle:resource-creation'])->name('cover');
    });
});
