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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('billing_interval')->default('monthly'); // monthly, yearly
            $table->unsignedInteger('max_courts')->default(5);
            $table->unsignedInteger('max_staff')->default(3);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('status')->default('active')->index(); // active, trialing, past_due, canceled
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending')->index(); // paid, pending, failed
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json
            $table->boolean('is_public')->default(false)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
