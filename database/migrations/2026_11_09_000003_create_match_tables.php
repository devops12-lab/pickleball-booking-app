<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table): void {
            $table->id();
            $table->string('match_type'); // singles, doubles
            $table->string('category'); // casual, competitive, tournament
            $table->json('format');
            $table->string('status')->default('scheduled')->index();
            $table->foreignId('venue_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('court_id')->nullable()->constrained('courts')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('open_play_sessions')->nullOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->nullOnDelete();
            $table->unsignedTinyInteger('first_serving')->default(0);
            $table->foreignId('scorer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('score')->nullable();
            $table->unsignedTinyInteger('winner_side')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('void_reason')->nullable();

            // The result workflow.
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('dispute_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('dispute_reason')->nullable();
            $table->timestamp('dispute_at')->nullable();
            $table->string('resolution_decision', 10)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolution_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolution_at')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'status']);
            $table->index(['tournament_id', 'status']);
        });

        Schema::create('match_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('side');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['match_id', 'user_id']);
            $table->index(['user_id', 'side']);
        });

        Schema::create('match_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('event_uuid');
            $table->string('type'); // rally, undo
            $table->unsignedTinyInteger('winner')->nullable();
            $table->foreignId('scorer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['match_id', 'event_uuid']);
            $table->unique(['match_id', 'seq']);
        });

        Schema::create('match_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['match_id', 'player_id']);
        });

        Schema::create('match_rating_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // singles, doubles
            $table->string('kind')->default('match'); // match, reversal
            $table->integer('old_rating');
            $table->integer('change');
            $table->integer('new_rating');
            $table->string('version')->default('elo-v1');
            $table->timestamps();

            $table->unique(['match_id', 'player_id', 'type', 'kind']);
            $table->index(['player_id', 'type', 'created_at']);
        });

        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->unsignedBigInteger('match_id')->nullable()->unique();
        });

        Schema::table('player_statistics', function (Blueprint $table): void {
            $table->unsignedInteger('singles_matches')->default(0);
            $table->unsignedInteger('singles_wins')->default(0);
            $table->unsignedInteger('singles_losses')->default(0);
            $table->unsignedInteger('doubles_matches')->default(0);
            $table->unsignedInteger('doubles_wins')->default(0);
            $table->unsignedInteger('doubles_losses')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('player_statistics', function (Blueprint $table): void {
            $table->dropColumn([
                'singles_matches', 'singles_wins', 'singles_losses',
                'doubles_matches', 'doubles_wins', 'doubles_losses',
            ]);
        });

        Schema::table('tournament_matches', function (Blueprint $table): void {
            $table->dropUnique(['match_id']);
            $table->dropColumn('match_id');
        });

        Schema::dropIfExists('match_rating_changes');
        Schema::dropIfExists('match_confirmations');
        Schema::dropIfExists('match_events');
        Schema::dropIfExists('match_players');
        Schema::dropIfExists('matches');
    }
};
