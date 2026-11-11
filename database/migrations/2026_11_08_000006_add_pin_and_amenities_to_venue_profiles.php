<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Players need a map pin and an amenity list to find a venue. Facilities carry
 * coordinates but the demo venue's are null, and amenities had nowhere to live
 * at all, so both move onto the venue profile where the club settings live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_profiles', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('province');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->json('amenities')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('venue_profiles', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'amenities']);
        });
    }
};
