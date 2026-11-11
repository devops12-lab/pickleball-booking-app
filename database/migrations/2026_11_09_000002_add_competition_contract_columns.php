<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->unsignedBigInteger('venue_id')->nullable();
            $table->string('host_type')->default('venue');
            $table->unsignedBigInteger('host_id')->nullable();
            $table->string('match_type')->default('doubles');
            $table->json('court_ids')->nullable();
            $table->json('game')->nullable();
            $table->unsignedInteger('capacity')->default(8);
            $table->unsignedTinyInteger('pools')->nullable();
            $table->unsignedBigInteger('champion_participant_id')->nullable();
            $table->text('cancel_reason')->nullable();
        });

        Schema::table('tournament_participants', function (Blueprint $table): void {
            $table->integer('rating')->nullable();
        });

        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->string('stage')->default('knockout');
            $table->string('pool', 1)->nullable();
            $table->boolean('bye')->default(false);
            $table->unsignedBigInteger('next_match_id')->nullable();
            $table->unsignedTinyInteger('next_side')->nullable();
        });

        Schema::table('player_statistics', function (Blueprint $table): void {
            $table->decimal('singles_rating', 5, 2)->nullable();
            $table->decimal('doubles_rating', 5, 2)->nullable();
        });

        // Existing rows predate the contract fields. Their venue is the tenant
        // they already belong to; the other values are the contract defaults.
        DB::table('tournaments')->whereNull('venue_id')->update([
            'venue_id' => DB::raw('tenant_id'),
            'host_id' => DB::raw('tenant_id'),
            'capacity' => DB::raw('max_teams'),
        ]);

        DB::table('player_statistics')->update([
            'singles_rating' => DB::raw('current_rating'),
            'doubles_rating' => DB::raw('current_rating'),
        ]);
    }

    public function down(): void
    {
        Schema::table('player_statistics', function (Blueprint $table): void {
            $table->dropColumn(['singles_rating', 'doubles_rating']);
        });

        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->dropColumn(['stage', 'pool', 'bye', 'next_match_id', 'next_side']);
        });

        Schema::table('tournament_participants', function (Blueprint $table): void {
            $table->dropColumn('rating');
        });

        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropColumn([
                'venue_id',
                'host_type',
                'host_id',
                'match_type',
                'court_ids',
                'game',
                'capacity',
                'pools',
                'champion_participant_id',
                'cancel_reason',
            ]);
        });
    }
};
