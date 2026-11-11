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
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('court_type')->default('indoor'); // indoor, outdoor, covered
            $table->string('surface_type')->nullable(); // acrylic, cushioned, concrete, wood
            $table->decimal('hourly_rate', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['facility_id', 'is_active']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('court_operating_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sunday, 1=Monday... 6=Saturday
            $table->time('open_time');
            $table->time('close_time');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['court_id', 'day_of_week']);
        });

        Schema::create('court_blocked_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['court_id', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('court_blocked_schedules');
        Schema::dropIfExists('court_operating_hours');
        Schema::dropIfExists('courts');
        Schema::dropIfExists('facilities');
    }
};
