<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geography for the location pickers (blueprint §4.4, §5.2 B) and the extra
 * profile fields a community club needs to appear in the directory.
 *
 * The geo rows themselves are reference data, seeded by GeoLocationSeeder with
 * the same IDs and PSGC codes the SPA's mocks use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_locations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->string('parent_id')->nullable()->index();
            $table->string('level'); // country, region, province, city, barangay
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
        });

        Schema::table('player_profiles', function (Blueprint $table): void {
            // The home barangay, chosen through /geo/barangays.
            $table->string('barangay_id')->nullable()->after('visibility')->index();
        });

        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('tagline')->nullable()->after('description');
            $table->string('city')->nullable()->after('tagline');
            $table->string('province')->nullable()->after('city');
            $table->string('cover')->nullable()->after('logo');
            // pending_review, active, suspended, rejected (CourtFlex reviews new clubs).
            $table->string('status')->default('active')->after('privacy')->index();
            $table->unsignedBigInteger('home_venue_id')->nullable()->after('tenant_id');
            $table->text('rules')->nullable();
            $table->text('waiver')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('join_policy')->default('open');
            $table->unsignedInteger('cancellation_hours')->default(12);
            $table->json('payment_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropColumn([
                'tagline', 'city', 'province', 'cover', 'status', 'home_venue_id',
                'rules', 'waiver', 'contact_email', 'contact_phone', 'join_policy',
                'cancellation_hours', 'payment_options',
            ]);
        });

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropColumn('barangay_id');
        });

        Schema::dropIfExists('geo_locations');
    }
};
