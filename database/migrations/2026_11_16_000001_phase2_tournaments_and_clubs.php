<?php

use App\Models\PlatformSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('banner_image')->nullable()->after('description');
        });

        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->foreignId('club_id')->nullable()->after('user_id')
                ->constrained('clubs')->nullOnDelete();
        });

        PlatformSetting::updateOrCreate(
            ['key' => 'max_clubs_per_player'],
            ['value' => '3', 'type' => 'integer', 'is_public' => true]
        );
    }

    public function down(): void
    {
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_id');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('banner_image');
        });
    }
};
