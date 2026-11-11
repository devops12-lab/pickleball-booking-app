<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The global player profile (blueprint §5.2). One row per player; a venue owner
 * or staff account that never plays has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('skill_level')->default('beginner');
            $table->text('bio')->nullable();
            $table->json('play_formats')->nullable();
            $table->json('preferred_times')->nullable();
            $table->string('visibility')->default('public');
            $table->boolean('location_verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profiles');
    }
};
