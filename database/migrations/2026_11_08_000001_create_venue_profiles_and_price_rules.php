<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SPA's club contract has venue fields this backend never stored: a tagline,
 * house rules, city/province/timezone, cover image, a review status and a
 * settings document. They live in their own table keyed by tenant so the
 * existing `clubs` table (a community group) stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('tagline')->default('');
            $table->text('description')->default('');
            $table->text('rules')->default('');
            $table->string('cover_url')->nullable();
            $table->string('contact_phone')->default('');
            $table->string('contact_email')->default('');
            $table->string('address')->default('');
            $table->string('city')->default('');
            $table->string('province')->default('');
            $table->string('timezone')->default('Asia/Manila');
            $table->string('status')->default('active');
            $table->string('status_reason')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('court_price_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::table('courts', function (Blueprint $table): void {
            $table->string('court_code')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->dropColumn('court_code');
        });

        Schema::dropIfExists('court_price_rules');
        Schema::dropIfExists('venue_profiles');
    }
};
