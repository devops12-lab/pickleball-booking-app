<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('form_type', 50); // tournament_registration, booking_request, advertisement_request, club_membership
            $table->json('draft_data');
            $table->timestamps();

            $table->index(['user_id', 'form_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_drafts');
    }
};
