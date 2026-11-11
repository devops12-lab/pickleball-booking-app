<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->string('avatar_style')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropColumn('avatar_style');
        });
    }
};
