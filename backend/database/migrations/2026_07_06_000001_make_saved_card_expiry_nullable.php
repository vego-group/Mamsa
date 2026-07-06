<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards tokenised from a real Moyasar payment carry no expiry in the gateway
 * response (brand + last4 + token only), so expiry becomes optional metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('exp_month')->nullable()->change();
            $table->unsignedSmallInteger('exp_year')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('saved_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('exp_month')->nullable(false)->change();
            $table->unsignedSmallInteger('exp_year')->nullable(false)->change();
        });
    }
};
