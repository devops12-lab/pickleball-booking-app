<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('initial')->default(1200);
            $table->unsignedInteger('provisional_matches')->default(5);
            $table->unsignedInteger('k_provisional')->default(32);
            $table->unsignedInteger('k_established')->default(24);
            $table->timestamps();
        });

        Schema::create('rating_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('adjusted_by')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->integer('old_rating');
            $table->integer('new_rating');
            $table->text('reason');
            $table->timestamps();

            $table->index(['player_id', 'type', 'created_at']);
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('adjustment_type')->default('refund');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('adjustment_type');
        });

        Schema::dropIfExists('rating_adjustments');
        Schema::dropIfExists('rating_rules');
    }
};
