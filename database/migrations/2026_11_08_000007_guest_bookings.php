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
        Schema::table('bookings', function (Blueprint $table) {
            // A guest has no account: the email code stands in for one.
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('user_id');
            $table->string('guest_phone')->nullable()->after('guest_name');
            $table->string('guest_email')->nullable()->after('guest_phone');

            $table->index(['guest_email', 'status']);
        });

        Schema::create('guest_booking_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('name');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_booking_codes');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['guest_email', 'status']);
            $table->dropColumn(['guest_name', 'guest_phone', 'guest_email']);
        });
    }
};
