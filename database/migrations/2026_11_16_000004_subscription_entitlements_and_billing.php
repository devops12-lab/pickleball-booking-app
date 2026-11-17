<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->decimal('yearly_price', 10, 2)->nullable()->after('price');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->string('billing_interval', 10)->default('monthly')->after('subscription_plan_id');
            $table->timestamp('current_period_start')->nullable()->after('trial_ends_at');
            $table->timestamp('current_period_end')->nullable()->after('current_period_start');
            $table->timestamp('cancelled_at')->nullable()->after('current_period_end');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->json('plan_snapshot')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('pending_plan_id')->nullable()->after('plan_snapshot');
            $table->string('pending_billing_interval', 10)->nullable()->after('pending_plan_id');

            $table->foreign('pending_plan_id')
                ->references('id')
                ->on('subscription_plans')
                ->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['current_period_end', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['pending_plan_id']);
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['current_period_end', 'status']);
            $table->dropColumn([
                'billing_interval',
                'current_period_start',
                'current_period_end',
                'cancelled_at',
                'cancellation_reason',
                'plan_snapshot',
                'pending_plan_id',
                'pending_billing_interval',
            ]);
        });

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn(['yearly_price', 'sort_order']);
        });
    }
};
