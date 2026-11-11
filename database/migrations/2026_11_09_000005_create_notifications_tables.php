<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('type', 64);
            $table->string('title');
            $table->text('body');
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 20);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('app_notifications');
    }
};
