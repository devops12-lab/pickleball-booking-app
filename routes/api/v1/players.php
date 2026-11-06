<?php

use App\Http\Controllers\Api\V1\Auth\PlayerAuthController;
use App\Http\Controllers\Api\V1\Players\BookingController;
use App\Http\Controllers\Api\V1\Players\ClubController;
use App\Http\Controllers\Api\V1\Players\DashboardController;
use App\Http\Controllers\Api\V1\Players\OpenPlayController;
use App\Http\Controllers\Api\V1\Players\PaymentController;
use App\Http\Controllers\Api\V1\Players\ProfileController;
use App\Http\Controllers\Api\V1\Players\RankingController;
use App\Http\Controllers\Api\V1\Players\TournamentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Player Authentication Routes (/api/v1/players/auth/*)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [PlayerAuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('/login', [PlayerAuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('/google', [PlayerAuthController::class, 'google'])->middleware('throttle:login')->name('google');
    Route::post('/forgot-password', [PlayerAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password')->name('password.forgot');
    Route::post('/reset-password', [PlayerAuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset');
    Route::post('/verify-email', [PlayerAuthController::class, 'verifyEmail'])->middleware('throttle:email-verification')->name('verify_email');

    Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function (): void {
        Route::get('/me', [PlayerAuthController::class, 'me'])->name('me');
        Route::post('/change-password', [PlayerAuthController::class, 'changePassword'])->middleware('throttle:sensitive')->name('password.change');
        Route::post('/email/verification-notification', [PlayerAuthController::class, 'sendVerificationNotification'])->middleware('throttle:email-verification')->name('email.notification');
        Route::post('/logout', [PlayerAuthController::class, 'logout'])->name('logout');
        Route::post('/tokens/revoke-all', [PlayerAuthController::class, 'revokeAll'])->name('tokens.revoke_all');
    });
});

/*
|--------------------------------------------------------------------------
| Player Business APIs (/api/v1/players/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.active', 'role:player', 'throttle:api'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:resource-creation')->name('bookings.store');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');

    Route::get('/clubs', [ClubController::class, 'index'])->name('clubs.index');
    Route::post('/clubs/{club}/join', [ClubController::class, 'join'])->middleware('throttle:resource-creation')->name('clubs.join');

    Route::get('/open-play', [OpenPlayController::class, 'index'])->name('open_play.index');
    Route::post('/open-play/{session}/join', [OpenPlayController::class, 'join'])->middleware('throttle:resource-creation')->name('open_play.join');

    Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
    Route::post('/tournaments/{tournament}/register', [TournamentController::class, 'register'])->middleware('throttle:resource-creation')->name('tournaments.register');

    Route::get('/rankings', [RankingController::class, 'index'])->name('rankings.index');
    Route::get('/statistics', [RankingController::class, 'statistics'])->name('statistics');
    Route::get('/payment-history', [PaymentController::class, 'history'])->name('payments.history');
    Route::post('/payments/{payment}/proof', [PaymentController::class, 'uploadProof'])->name('payments.proof');
});
