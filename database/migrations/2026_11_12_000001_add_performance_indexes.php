<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_price_rules', function (Blueprint $table): void {
            $table->index(['court_id', 'day_of_week', 'starts_at'], 'cpr_court_dow_start');
        });

        Schema::table('club_members', function (Blueprint $table): void {
            $table->index(['club_id', 'status'], 'cm_club_status');
        });
    }

    public function down(): void
    {
        Schema::table('court_price_rules', function (Blueprint $table): void {
            $table->dropIndex('cpr_court_dow_start');
        });

        Schema::table('club_members', function (Blueprint $table): void {
            $table->dropIndex('cm_club_status');
        });
    }
};
