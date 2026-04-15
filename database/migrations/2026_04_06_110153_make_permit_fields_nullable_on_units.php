<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('units', function (Blueprint $table) {
        $table->string('tourism_permit_no')->nullable()->change();
        $table->string('company_license_no')->nullable()->change();
        $table->string('tourism_permit_file')->nullable()->change();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
