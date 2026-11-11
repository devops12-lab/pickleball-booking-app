<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedTinyInteger('session_discount_percent')->default(0);
            $table->json('perks')->nullable();
            $table->boolean('recommended')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['club_id', 'is_active']);
        });

        Schema::create('club_member_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_member_id')->constrained('club_members')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['club_member_id', 'occurred_at']);
        });

        Schema::create('club_membership_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->foreignId('club_member_id')->constrained('club_members')->cascadeOnDelete();
            $table->foreignId('payer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method');
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->string('description');
            $table->timestamps();

            $table->index(['club_id', 'received_at']);
            $table->index(['club_member_id', 'received_at']);
        });

        Schema::table('club_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_id')->nullable()->index()->after('status');
            $table->timestamp('starts_at')->nullable()->after('joined_at');
            $table->timestamp('expires_at')->nullable()->after('starts_at');
            $table->timestamp('requested_at')->nullable()->after('expires_at');
            $table->json('application')->nullable()->after('requested_at');
            $table->json('payment')->nullable()->after('application');
            $table->decimal('dues_due', 10, 2)->default(0)->after('payment');
        });

        Schema::table('open_play_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('queue_version')->default(0)->after('court_fee');
        });

        Schema::table('open_play_participants', function (Blueprint $table): void {
            $table->unsignedInteger('queue_position')->nullable()->after('queue_state');
            $table->timestamp('queue_since')->nullable()->after('queue_position');
        });

        Schema::create('open_play_queue_games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('open_play_session_id')->constrained('open_play_sessions')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->json('team_a');
            $table->json('team_b');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('winner', 1)->nullable();
            $table->timestamps();

            $table->index(['open_play_session_id', 'ended_at']);
            $table->index(['court_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_play_queue_games');

        Schema::table('open_play_participants', function (Blueprint $table): void {
            $table->dropColumn(['queue_position', 'queue_since']);
        });

        Schema::table('open_play_sessions', function (Blueprint $table): void {
            $table->dropColumn('queue_version');
        });

        Schema::table('club_members', function (Blueprint $table): void {
            $table->dropColumn([
                'plan_id', 'starts_at', 'expires_at', 'requested_at',
                'application', 'payment', 'dues_due',
            ]);
        });

        Schema::dropIfExists('club_membership_payments');
        Schema::dropIfExists('club_member_events');
        Schema::dropIfExists('club_membership_plans');
    }
};
