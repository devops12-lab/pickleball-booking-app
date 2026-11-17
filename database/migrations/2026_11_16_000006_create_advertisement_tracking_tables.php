<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisement_impressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 64)->nullable();
            $table->string('ip_hash', 64);
            $table->timestamp('viewed_at');
            $table->string('placement', 20);

            $table->index(['advertisement_id', 'viewed_at']);
            $table->index(['user_id', 'advertisement_id', 'placement', 'viewed_at'], 'ad_imp_user_ad_placement_viewed');
        });

        Schema::create('advertisement_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 64)->nullable();
            $table->string('ip_hash', 64);
            $table->timestamp('clicked_at');
            $table->string('destination_url', 500)->nullable();

            $table->index(['advertisement_id', 'clicked_at']);
            $table->index(['user_id', 'advertisement_id', 'clicked_at'], 'ad_click_user_ad_clicked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_clicks');
        Schema::dropIfExists('advertisement_impressions');
    }
};
