<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'calendar_token')) {
                $table->string('calendar_token', 64)->nullable()->unique()->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (Schema::hasColumn('units', 'calendar_token')) {
                $table->dropColumn('calendar_token');
            }
        });
    }
};