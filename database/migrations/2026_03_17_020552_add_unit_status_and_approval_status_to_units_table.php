<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (!Schema::hasColumn('units', 'unit_status')) {
                $table->string('unit_status')->default('available')->after('status');
            }

            if (!Schema::hasColumn('units', 'approval_status')) {
                $table->string('approval_status')->default('approved')->after('unit_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (Schema::hasColumn('units', 'unit_status')) {
                $table->dropColumn('unit_status');
            }

            if (Schema::hasColumn('units', 'approval_status')) {
                $table->dropColumn('approval_status');
            }
        });
    }
};