<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('player_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('matches_played')->default(0);
            $table->unsignedInteger('matches_won')->default(0);
            $table->unsignedInteger('matches_lost')->default(0);
            $table->unsignedInteger('tournaments_played')->default(0);
            $table->unsignedInteger('tournaments_won')->default(0);
            $table->decimal('current_rating', 5, 2)->default(1200.00);
            $table->timestamps();
        });

        Schema::create('player_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category')->default('general'); // general, singles, doubles, mixed
            $table->unsignedInteger('rank');
            $table->unsignedInteger('points')->default(0);
            $table->date('recorded_date');
            $table->timestamps();

            $table->index(['category', 'recorded_date', 'rank']);
            $table->index(['user_id', 'recorded_date']);
        });

        Schema::create('match_results', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('matchable');
            $table->foreignId('participant1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('participant2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('winner_id')->constrained('users')->cascadeOnDelete();
            $table->json('scores');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['participant1_id', 'created_at']);
            $table->index(['participant2_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_results');
        Schema::dropIfExists('player_rankings');
        Schema::dropIfExists('player_statistics');
    }
};
