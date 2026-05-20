<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('moyasar_id')->nullable()->after('booking_id')->index();
            $table->string('moyasar_reference')->nullable()->after('moyasar_id');
            $table->json('moyasar_response')->nullable()->after('moyasar_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['moyasar_id', 'moyasar_reference', 'moyasar_response']);
        });
    }
};
