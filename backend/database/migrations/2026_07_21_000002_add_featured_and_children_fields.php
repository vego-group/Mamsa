<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frontend field-gap task (2026-07-21):
 *  - units.is_featured  — powers the "وحدات مميزة" home section (§2.1).
 *  - bookings.children   — split guest counts (§2.3); existing `guests`
 *    stays the TOTAL, adults are derived as guests − children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('children')->default(0)->after('guests');
        });
    }

    public function down(): void
    {
        Schema::table('units', fn (Blueprint $t) => $t->dropColumn('is_featured'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('children'));
    }
};
