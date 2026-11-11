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
        Schema::create('open_play_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_play_session_id')->constrained('open_play_sessions')->cascadeOnDelete();
            $table->foreignId('open_play_participant_id')->nullable()->constrained('open_play_participants')->nullOnDelete();
            $table->string('kind'); // session_fee | court_fee
            $table->foreignId('payer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method');
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['open_play_session_id', 'kind']);
            $table->index(['open_play_participant_id', 'kind']);
        });

        Schema::create('open_play_fee_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_play_fee_payment_id')->constrained('open_play_fee_payments')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reason');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_play_fee_refunds');
        Schema::dropIfExists('open_play_fee_payments');
    }
};
