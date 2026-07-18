<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing model change (frontend contract 2026-07-18):
 * - cleaning fee moves from a platform-wide config value to a per-unit,
 *   partner-editable column (default 0 — existing units keep no fee rather
 *   than inheriting the old 300 SAR design placeholder).
 * - service fee % becomes superadmin-editable at runtime via a key/value
 *   platform_settings row; tax % stays config-only (legal VAT rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('cleaning_fee', 8, 2)->default(0)->after('price');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });

        // Seed from the pre-migration platform default (10%).
        DB::table('platform_settings')->insert([
            'key'        => 'service_fee_percent',
            'value'      => '10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('cleaning_fee');
        });
    }
};
