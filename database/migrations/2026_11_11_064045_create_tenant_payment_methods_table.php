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
        Schema::create('tenant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100); // e.g. "GCash - 0917xxx", "BPI - 1234xxxx"
            $table->string('type'); // cash, gcash, maya, qrph, bank_transfer, other
            $table->string('account_name')->nullable();
            $table->string('account_number', 100)->nullable();
            $table->text('instructions')->nullable();
            $table->string('qr_image')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_methods');
    }
};
