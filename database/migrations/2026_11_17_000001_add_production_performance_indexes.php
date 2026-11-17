<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing indexes identified during the production readiness audit.
     *
     * These columns are frequently used in WHERE, ORDER BY or JOIN clauses
     * but were not previously indexed.
     *
     * Note: clubs.status is already indexed (2026_11_08_000010 migration).
     */
    public function up(): void
    {
        // High priority — heavily queried columns

        Schema::table('courts', function (Blueprint $table): void {
            $table->index('status');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->index('hold_expires_at');
            $table->index('source');
        });

        Schema::table('open_play_participants', function (Blueprint $table): void {
            $table->index('status');
            $table->index('fee_status');
        });

        // Medium priority — frequently filtered columns

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->index('skill_level');
            $table->index('visibility');
        });

        Schema::table('venue_profiles', function (Blueprint $table): void {
            $table->index('status');
            $table->index('barangay_id');
        });

        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->index('category');
        });

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->index('category');
        });

        // Low priority — foreign key optimization

        Schema::table('user_permissions', function (Blueprint $table): void {
            $table->index('permission_id');
        });

        Schema::table('booking_receipts', function (Blueprint $table): void {
            $table->index('reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->dropIndex(['status']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['hold_expires_at']);
            $table->dropIndex(['source']);
        });

        Schema::table('open_play_participants', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['fee_status']);
        });

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropIndex(['skill_level']);
            $table->dropIndex(['visibility']);
        });

        Schema::table('venue_profiles', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['barangay_id']);
        });

        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->dropIndex(['category']);
        });

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropIndex(['category']);
        });

        Schema::table('user_permissions', function (Blueprint $table): void {
            $table->dropIndex(['permission_id']);
        });

        Schema::table('booking_receipts', function (Blueprint $table): void {
            $table->dropIndex(['reviewed_by']);
        });
    }
};
