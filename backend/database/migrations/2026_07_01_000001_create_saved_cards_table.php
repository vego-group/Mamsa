<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokenised saved cards for one-click checkout (backend gaps #4 — Saved Cards).
 *
 * SECURITY: only non-sensitive metadata is stored (brand + last 4 + expiry).
 * The full PAN is never persisted; `moyasar_token` holds the gateway token
 * once real tokenisation is wired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('brand', ['visa', 'mastercard', 'mada']);
            $table->char('last4', 4);
            $table->unsignedTinyInteger('exp_month');   // 1–12
            $table->unsignedSmallInteger('exp_year');   // 4-digit year
            $table->boolean('is_default')->default(false);

            // Moyasar token — never the raw card number.
            $table->string('moyasar_token')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_cards');
    }
};
