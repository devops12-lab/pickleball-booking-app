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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('business_email');
            $table->string('business_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('logo')->nullable();
            $table->string('status')->default('active')->index(); // active, suspended, trial
            $table->unsignedBigInteger('subscription_plan_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // system_admin, owner, staff, player, club_manager
            $table->string('label');
            $table->string('scope')->default('tenant')->index(); // platform, tenant
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('group')->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('status')->default('active')->index(); // active, inactive, invited
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('is_revoked')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tenants');
    }
};
