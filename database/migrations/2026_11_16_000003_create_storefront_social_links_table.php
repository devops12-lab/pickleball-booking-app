<?php

use App\Models\CourtFront;
use App\Models\StorefrontSocialLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_social_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_front_id')->constrained('court_fronts')->cascadeOnDelete();
            $table->string('platform', 50); // facebook, instagram, tiktok, youtube, linkedin, twitter, website
            $table->string('display_name', 100)->nullable();
            $table->string('url', 500);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['court_front_id', 'is_active']);
        });

        // Migrate existing inline social columns to the new table
        $this->migrateExistingData();

        // Drop old columns
        Schema::table('court_fronts', function (Blueprint $table) {
            $table->dropColumn(['facebook', 'instagram', 'website']);
        });
    }

    public function down(): void
    {
        Schema::table('court_fronts', function (Blueprint $table) {
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
        });

        Schema::dropIfExists('storefront_social_links');
    }

    private function migrateExistingData(): void
    {
        CourtFront::query()
            ->where(function ($q) {
                $q->whereNotNull('facebook')
                    ->orWhereNotNull('instagram')
                    ->orWhereNotNull('website');
            })
            ->each(function (CourtFront $front): void {
                $order = 0;

                if ($front->facebook) {
                    StorefrontSocialLink::create([
                        'court_front_id' => $front->id,
                        'platform' => 'facebook',
                        'url' => $front->facebook,
                        'sort_order' => $order++,
                        'is_active' => true,
                    ]);
                }

                if ($front->instagram) {
                    StorefrontSocialLink::create([
                        'court_front_id' => $front->id,
                        'platform' => 'instagram',
                        'url' => $front->instagram,
                        'sort_order' => $order++,
                        'is_active' => true,
                    ]);
                }

                if ($front->website) {
                    StorefrontSocialLink::create([
                        'court_front_id' => $front->id,
                        'platform' => 'website',
                        'url' => $front->website,
                        'sort_order' => $order,
                        'is_active' => true,
                    ]);
                }
            });
    }
};
