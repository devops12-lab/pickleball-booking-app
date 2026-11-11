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
        Schema::table('open_play_sessions', function (Blueprint $table) {
            // A session is hosted by a venue or a community club, on one or more courts.
            $table->string('organizer_type')->default('venue')->after('tenant_id');
            $table->unsignedBigInteger('organizer_id')->nullable()->after('organizer_type');
            $table->unsignedBigInteger('host_id')->nullable()->after('organizer_id');
            $table->string('session_type')->default('open_play')->after('description');
            $table->string('skill_level')->default('all')->after('session_type');
            $table->dateTime('registration_deadline')->nullable()->after('ends_at');
            $table->decimal('member_fee', 10, 2)->default(0.00)->after('fee');
            $table->boolean('members_only')->default(false)->after('member_fee');
            $table->string('status_reason')->nullable()->after('status');
            $table->decimal('court_fee', 10, 2)->default(0.00)->after('status_reason');

            $table->index(['organizer_type', 'organizer_id']);
        });

        Schema::create('open_play_session_courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_play_session_id')->constrained('open_play_sessions')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['open_play_session_id', 'court_id']);
        });

        Schema::table('open_play_participants', function (Blueprint $table) {
            $table->string('status')->default('registered')->after('user_id');
            $table->unsignedInteger('waitlist_position')->nullable()->after('status');
            $table->decimal('fee_due', 10, 2)->default(0.00)->after('waitlist_position');
            $table->decimal('fee_paid', 10, 2)->default(0.00)->after('fee_due');
            $table->string('fee_status')->default('unpaid')->after('fee_paid');
            $table->string('queue_state')->nullable()->after('fee_status');
            $table->unsignedInteger('games_played')->default(0)->after('queue_state');
            $table->boolean('is_member')->default(false)->after('games_played');
            $table->timestamp('checked_in_at')->nullable()->after('check_in_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('open_play_participants', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'waitlist_position', 'fee_due', 'fee_paid', 'fee_status',
                'queue_state', 'games_played', 'is_member', 'checked_in_at',
            ]);
        });

        Schema::dropIfExists('open_play_session_courts');

        Schema::table('open_play_sessions', function (Blueprint $table) {
            $table->dropIndex(['organizer_type', 'organizer_id']);
            $table->dropColumn([
                'organizer_type', 'organizer_id', 'host_id', 'session_type', 'skill_level',
                'registration_deadline', 'member_fee', 'members_only', 'status_reason', 'court_fee',
            ]);
        });
    }
};
