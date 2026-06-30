<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedTinyInteger('bathrooms')->nullable()->after('bedrooms');
            $table->unsignedInteger('area')->nullable()->after('bathrooms'); // built-up area in m²
        });

        // Backfill existing rows with plausible values so listing cards render fully.
        DB::table('units')->whereNull('bathrooms')
            ->update(['bathrooms' => DB::raw('GREATEST(1, bedrooms)')]);
        DB::table('units')->whereNull('area')
            ->update(['area' => DB::raw('(bedrooms * 60 + capacity * 15 + 40)')]);
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['bathrooms', 'area']);
        });
    }
};
