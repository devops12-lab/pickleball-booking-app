<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->unsignedBigInteger('runner_up_participant_id')->nullable()->after('champion_participant_id');
            $table->unsignedBigInteger('third_place_participant_id')->nullable()->after('runner_up_participant_id');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropColumn(['runner_up_participant_id', 'third_place_participant_id']);
        });
    }
};
