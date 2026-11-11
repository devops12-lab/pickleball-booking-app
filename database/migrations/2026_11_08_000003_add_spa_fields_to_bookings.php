<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SPA tracks where a booking came from, how it will be paid, how long an
 * unpaid slot is held, and the e-wallet receipt waiting for staff review.
 * None of that had a column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->after('user_id');
            $table->string('source')->default('player_app')->after('status');
            $table->string('payment_method')->nullable()->after('source');
            $table->timestamp('hold_expires_at')->nullable()->after('payment_method');
        });

        Schema::create('booking_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('image_url');
            $table->string('reference_number');
            $table->string('status')->default('in_review')->index();
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_receipts');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['created_by', 'source', 'payment_method', 'hold_expires_at']);
        });
    }
};
