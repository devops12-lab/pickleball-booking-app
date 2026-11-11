<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SPA distinguishes inactive from maintenance; `is_active` alone cannot.
 * The column is nullable and backfilled from is_active where it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->string('status')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
