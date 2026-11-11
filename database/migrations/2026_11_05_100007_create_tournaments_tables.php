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
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('format')->default('single_elimination'); // single_elimination, double_elimination, round_robin
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('registration_deadline')->nullable();
            $table->decimal('entry_fee', 10, 2)->default(0.00);
            $table->unsignedInteger('max_teams')->default(16);
            $table->string('status')->default('upcoming')->index(); // upcoming, registration_open, in_progress, completed, cancelled
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('tournament_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->string('name');
            $table->string('division')->nullable(); // singles, doubles, mixed
            $table->string('skill_level')->nullable(); // 3.0, 3.5, 4.0, 4.5+
            $table->timestamps();
        });

        Schema::create('tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('tournament_category_id')->nullable()->constrained('tournament_categories')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('team_name')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->unsignedInteger('seed')->nullable();
            $table->string('status')->default('registered')->index(); // registered, confirmed, eliminated, withdrawn
            $table->timestamps();
        });

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('tournament_category_id')->nullable()->constrained('tournament_categories')->nullOnDelete();
            $table->foreignId('court_id')->nullable()->constrained('courts')->nullOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->unsignedInteger('match_number')->default(1);
            $table->foreignId('participant1_id')->nullable()->constrained('tournament_participants')->nullOnDelete();
            $table->foreignId('participant2_id')->nullable()->constrained('tournament_participants')->nullOnDelete();
            $table->foreignId('winner_id')->nullable()->constrained('tournament_participants')->nullOnDelete();
            $table->string('score')->nullable();
            $table->string('status')->default('scheduled')->index(); // scheduled, in_progress, completed, forfeit
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tournament_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('tournament_category_id')->nullable()->constrained('tournament_categories')->nullOnDelete();
            $table->json('bracket_data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_brackets');
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_participants');
        Schema::dropIfExists('tournament_categories');
        Schema::dropIfExists('tournaments');
    }
};
