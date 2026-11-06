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
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('privacy')->default('public'); // public, private, invite_only
            $table->unsignedInteger('max_members')->default(50);
            $table->timestamps();

            $table->index(['tenant_id', 'privacy']);
        });

        Schema::create('club_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member'); // manager, organizer, member
            $table->string('status')->default('active')->index(); // active, pending, banned
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->unique(['club_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('club_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('invited_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invited_email')->nullable();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('status')->default('pending')->index(); // pending, accepted, declined, expired
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('club_invitations');
        Schema::dropIfExists('club_members');
        Schema::dropIfExists('clubs');
    }
};
