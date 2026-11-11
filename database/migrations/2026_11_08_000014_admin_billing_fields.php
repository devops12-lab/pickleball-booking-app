<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->after('status');
            $table->string('payment_method')->nullable()->after('paid_at');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->text('void_reason')->nullable()->after('payment_reference');
        });

        // The SPA's plan form treats an empty limit as "no limit", which the
        // contract sends as null. Keep that meaning in the database too.
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_courts')->nullable()->default(null)->change();
            $table->unsignedInteger('max_staff')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_courts')->nullable(false)->default(5)->change();
            $table->unsignedInteger('max_staff')->nullable(false)->default(3)->change();
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropColumn(['due_at', 'payment_method', 'payment_reference', 'void_reason']);
        });
    }
};
