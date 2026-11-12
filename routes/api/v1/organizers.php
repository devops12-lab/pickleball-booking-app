<?php

use App\Http\Controllers\Api\V1\Auth\OrganizerAuthController;
use App\Http\Controllers\Api\V1\Organizers\ClubController;
use App\Http\Controllers\Api\V1\Organizers\DashboardController;
use App\Http\Controllers\Api\V1\Organizers\MatchResultController;
use App\Http\Controllers\Api\V1\Organizers\TournamentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Club Organizer Authentication Routes (/api/v1/organizers/auth/*)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/login', [OrganizerAuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('/google', [OrganizerAuthController::class, 'google'])->middleware('throttle:login')->name('google');
    Route::post('/forgot-password', [OrganizerAuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password')->name('password.forgot');
    Route::post('/reset-password', [OrganizerAuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset');

    Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function (): void {
        Route::get('/me', [OrganizerAuthController::class, 'me'])->name('me');
        Route::post('/change-password', [OrganizerAuthController::class, 'changePassword'])->middleware('throttle:sensitive')->name('password.change');
        Route::post('/logout', [OrganizerAuthController::class, 'logout'])->name('logout');
        Route::post('/tokens/revoke-all', [OrganizerAuthController::class, 'revokeAll'])->name('tokens.revoke_all');
        Route::post('/accept-assignment', [OrganizerAuthController::class, 'acceptAssignment'])->middleware('throttle:sensitive')->name('accept_assignment');
    });
});

/*
|--------------------------------------------------------------------------
| Club Organizer Business APIs (/api/v1/organizers/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.active', 'role:club_manager,owner,system_admin', 'throttle:api'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/clubs', [ClubController::class, 'index'])->name('clubs.index');
    Route::post('/clubs', [ClubController::class, 'store'])->middleware('throttle:resource-creation')->name('clubs.store');
    Route::get('/clubs/{club}', [ClubController::class, 'show'])->name('clubs.show');
    Route::patch('/clubs/{club}', [ClubController::class, 'update'])->name('clubs.update');
    Route::delete('/clubs/{club}', [ClubController::class, 'destroy'])->name('clubs.destroy');
    Route::get('/clubs/{club}/members', [ClubController::class, 'members'])->name('clubs.members');
    Route::post('/clubs/{club}/members/{player}/approve', [ClubController::class, 'approveMember'])->name('clubs.members.approve');

    Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
    Route::post('/tournaments', [TournamentController::class, 'store'])->middleware('throttle:resource-creation')->name('tournaments.store');
    Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
    Route::patch('/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::delete('/tournaments/{tournament}', [TournamentController::class, 'destroy'])->name('tournaments.destroy');

    Route::post('/tournaments/{tournament}/matches/{match}/results', [MatchResultController::class, 'submitScore'])
        ->scopeBindings()
        ->name('tournaments.matches.results');
});
