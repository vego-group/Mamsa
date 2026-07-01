<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet / transaction ledger (backend gaps #4 — Wallet Transactions).
 * `amount` is signed: positive = incoming (refund/topup/reward),
 * negative = outgoing (payment). Powers GET /user/transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('ref_code', 40);
            $table->enum('type', ['payment', 'refund', 'topup', 'reward']);
            $table->decimal('amount', 12, 2); // signed
            $table->string('description')->nullable();
            $table->enum('status', ['completed', 'pending', 'failed'])->default('completed');

            // Optional link back to the booking that produced the entry.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            $table->date('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
