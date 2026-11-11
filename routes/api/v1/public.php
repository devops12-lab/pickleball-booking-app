<?php

use App\Http\Controllers\Api\V1\Public\ClubController;
use App\Http\Controllers\Api\V1\Public\CourtController;
use App\Http\Controllers\Api\V1\Public\CourtFrontController;
use App\Http\Controllers\Api\V1\Public\FacilityController;
use App\Http\Controllers\Api\V1\Public\OpenPlayController;
use App\Http\Controllers\Api\V1\Public\RankingController;
use App\Http\Controllers\Api\V1\Public\TournamentController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:public-api')->group(function (): void {
    Route::get('/facilities', [FacilityController::class, 'index'])->name('facilities.index');
    Route::get('/facilities/{facility}', [FacilityController::class, 'show'])->name('facilities.show');

    Route::get('/tenants/{tenant}/court-front', [CourtFrontController::class, 'show'])
        ->whereNumber('tenant')
        ->name('court_fronts.show');

    Route::get('/courts', [CourtController::class, 'index'])->name('courts.index');
    Route::get('/courts/{court}', [CourtController::class, 'show'])->name('courts.show');
    Route::get('/courts/{court}/availability', [CourtController::class, 'checkAvailability'])->name('courts.availability');

    Route::get('/clubs', [ClubController::class, 'index'])->name('clubs.index');
    Route::get('/clubs/{club}', [ClubController::class, 'show'])->name('clubs.show');

    Route::get('/open-play', [OpenPlayController::class, 'index'])->name('open_play.index');
    Route::get('/open-play/{session}', [OpenPlayController::class, 'show'])->name('open_play.show');

    Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
    Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');

    Route::get('/rankings', [RankingController::class, 'index'])->name('rankings.index');
});
