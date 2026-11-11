<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Six-digit email verification codes for player sign-up. One live code per
 * user: a resend replaces it and resets the attempt counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_sent_at');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
