<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

/*
|--------------------------------------------------------------------------
| Database Backups
|--------------------------------------------------------------------------
| The schedule runner is minute based so administrators can change the
| daily / weekly / monthly execution time in the database at runtime. Every
| schedule row is still respected and disabled rows are skipped.
*/
Schedule::command('database:backup-schedule-run')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('database:backup-cleanup')
    ->dailyAt('04:30')
    ->withoutOverlapping(30)
    ->onOneServer();
