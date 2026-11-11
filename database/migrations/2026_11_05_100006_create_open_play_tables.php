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
        Schema::create('open_play_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('max_participants')->default(16);
            $table->decimal('fee', 10, 2)->default(0.00);
            $table->decimal('skill_level_min', 3, 1)->nullable();
            $table->decimal('skill_level_max', 3, 1)->nullable();
            $table->string('status')->default('scheduled')->index(); // scheduled, in_progress, completed, cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['court_id', 'starts_at']);
        });

        Schema::create('open_play_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_play_session_id')->constrained('open_play_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('check_in_status')->default('registered')->index(); // registered, checked_in, no_show
            $table->timestamps();

            $table->unique(['open_play_session_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_play_participants');
        Schema::dropIfExists('open_play_sessions');
    }
};
