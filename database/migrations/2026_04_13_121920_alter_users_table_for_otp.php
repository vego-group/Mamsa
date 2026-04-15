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
    Schema::table('users', function (Blueprint $table) {

        if (!Schema::hasColumn('users', 'phone')) {
            $table->string('phone')->unique()->after('id');
        }

        $table->string('name')->nullable()->change();
        $table->string('email')->nullable()->change();
        $table->string('password')->nullable()->change();

        if (!Schema::hasColumn('users', 'is_active')) {
            $table->boolean('is_active')->default(true);
        }
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
