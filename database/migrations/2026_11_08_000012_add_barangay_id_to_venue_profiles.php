<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The barangay a venue pinned during owner sign-up. Venue listings use the
     * city/province text; the barangay is kept for the location the owner chose.
     */
    public function up(): void
    {
        if (Schema::hasColumn('venue_profiles', 'barangay_id')) {
            return;
        }

        Schema::table('venue_profiles', function (Blueprint $table) {
            $table->string('barangay_id')->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('venue_profiles', 'barangay_id')) {
            return;
        }

        Schema::table('venue_profiles', function (Blueprint $table) {
            $table->dropColumn('barangay_id');
        });
    }
};
