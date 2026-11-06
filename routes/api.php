<?php

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
});
