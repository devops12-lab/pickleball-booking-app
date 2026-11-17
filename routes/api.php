<?php

use App\Http\Controllers\Api\V1\AdvertisementTrackingController;
use App\Http\Controllers\Api\V1\FormDraftController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CourtFlex API Routes
|--------------------------------------------------------------------------
|
| Role-based API route prefix architecture for CourtFlex.
| Centralized authentication is shared, with distinct namespaces
| and server-side authorization per primary user role.
|
*/

Route::prefix('v1')->group(function (): void {
    // --------------------------------------------------------------------
    // SPA Compatibility API (/auth/login, /auth/logout, /me)
    // --------------------------------------------------------------------
    Route::group([], base_path('routes/api/v1/compat.php'));

    // --------------------------------------------------------------------
    // Public Discovery API (No authentication required)
    // --------------------------------------------------------------------
    Route::prefix('public')
        ->name('public.')
        ->group(base_path('routes/api/v1/public.php'));

    // --------------------------------------------------------------------
    // System Admin API
    // --------------------------------------------------------------------
    Route::prefix('admin')
        ->name('admin.')
        ->group(base_path('routes/api/v1/admin.php'));

    // --------------------------------------------------------------------
    // Facility Owner API
    // --------------------------------------------------------------------
    Route::prefix('owners')
        ->name('owners.')
        ->group(base_path('routes/api/v1/owners.php'));

    // --------------------------------------------------------------------
    // Facility Staff Operations API
    // --------------------------------------------------------------------
    Route::prefix('staff')
        ->name('staff.')
        ->group(base_path('routes/api/v1/staff.php'));

    // --------------------------------------------------------------------
    // Player API
    // --------------------------------------------------------------------
    Route::prefix('players')
        ->name('players.')
        ->group(base_path('routes/api/v1/players.php'));

    // --------------------------------------------------------------------
    // Club Manager / Organizer API
    // --------------------------------------------------------------------
    Route::prefix('organizers')
        ->name('organizers.')
        ->group(base_path('routes/api/v1/organizers.php'));

    // ----------------------------------------------------------------
    // Shared Authenticated API (any role)
    // ----------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function (): void {
        Route::prefix('form-drafts')->name('form_drafts.')->group(function (): void {
            Route::get('/', [FormDraftController::class, 'index'])->name('index');
            Route::post('/', [FormDraftController::class, 'store'])->name('store');
            Route::get('/{formDraft}', [FormDraftController::class, 'show'])->name('show');
            Route::patch('/{formDraft}', [FormDraftController::class, 'update'])->name('update');
            Route::delete('/{formDraft}', [FormDraftController::class, 'destroy'])->name('destroy');
        });

        // Advertisement tracking (any authenticated user)
        Route::post('/advertisements/{advertisement}/impression', [AdvertisementTrackingController::class, 'impression'])->name('advertisements.impression');
        Route::post('/advertisements/{advertisement}/click', [AdvertisementTrackingController::class, 'click'])->name('advertisements.click');
    });
});
