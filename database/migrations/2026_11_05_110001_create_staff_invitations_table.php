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
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email')->index();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('token', 100)->unique();
            $table->string('status')->default('pending')->index(); // pending, accepted, declined, expired
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
