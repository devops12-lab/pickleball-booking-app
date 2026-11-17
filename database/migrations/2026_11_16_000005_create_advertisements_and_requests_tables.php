<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('destination_url', 500)->nullable();
            $table->string('type', 20);
            $table->string('placement', 20);
            $table->string('target_audience', 20)->default('all');
            $table->string('media_path')->nullable();
            $table->string('media_type', 20)->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('priority')->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('impressions_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status', 'start_date', 'end_date']);
            $table->index(['placement', 'status']);
            $table->index(['target_audience', 'status']);
        });

        Schema::create('advertisement_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('requester_type', 20);
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->text('objective')->nullable();
            $table->string('requested_placement', 20)->nullable();
            $table->date('requested_start_date')->nullable();
            $table->date('requested_end_date')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('additional_requirements')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('admin_response')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_requests');
        Schema::dropIfExists('advertisements');
    }
};
