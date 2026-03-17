<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'calendar_external_url')) {
                $table->string('calendar_external_url', 2048)->nullable()->after('calendar_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (Schema::hasColumn('units', 'calendar_external_url')) {
                $table->dropColumn('calendar_external_url');
            }
        });
    }
};