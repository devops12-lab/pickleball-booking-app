<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 17: the Super Admin needs to know when a venue was submitted and why
 * an account was suspended. The venue profile gains the submission time; each
 * suspension is a row so the reason and actor survive reactivation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('venue_profiles', 'submitted_at')) {
            Schema::table('venue_profiles', function (Blueprint $table): void {
                $table->timestamp('submitted_at')->nullable()->after('status_reason');
            });
        }

        if (! Schema::hasTable('user_suspensions')) {
            Schema::create('user_suspensions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason');
                $table->timestamp('suspended_at');
                $table->timestamp('lifted_at')->nullable();
                $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('lift_reason')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'lifted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_suspensions');

        if (Schema::hasColumn('venue_profiles', 'submitted_at')) {
            Schema::table('venue_profiles', function (Blueprint $table): void {
                $table->dropColumn('submitted_at');
            });
        }
    }
};
