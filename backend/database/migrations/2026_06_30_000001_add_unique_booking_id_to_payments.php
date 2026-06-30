<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce one payment row per booking at the database level. The controller
 * already uses Payment::firstOrCreate(['booking_id' => ...]) but without a
 * unique constraint two concurrent requests can race into duplicate rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
        });
    }
};
