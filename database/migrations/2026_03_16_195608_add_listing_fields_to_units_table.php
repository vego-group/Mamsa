<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'type')) {
                $table->string('type', 50)->nullable()->after('description'); // apartment|villa|studio
            }
            if (!Schema::hasColumn('units', 'bedrooms')) {
                $table->unsignedTinyInteger('bedrooms')->nullable()->after('type');
            }
            if (!Schema::hasColumn('units', 'capacity')) {
                $table->unsignedSmallInteger('capacity')->nullable()->after('bedrooms');
            }
            if (!Schema::hasColumn('units', 'city')) {
                $table->string('city', 100)->nullable()->after('capacity');
            }
            if (!Schema::hasColumn('units', 'district')) {
                $table->string('district', 100)->nullable()->after('city');
            }
            if (!Schema::hasColumn('units', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable()->after('district');
            }
            if (!Schema::hasColumn('units', 'lng')) {
                $table->decimal('lng', 10, 7)->nullable()->after('lat');
            }
            if (!Schema::hasColumn('units', 'calendar_external_url')) {
                $table->string('calendar_external_url', 2048)->nullable()->after('calendar_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            foreach (['type','bedrooms','capacity','city','district','lat','lng','calendar_external_url'] as $col) {
                if (Schema::hasColumn('units', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
