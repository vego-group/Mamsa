<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable partner ledger — contract v2.2 §2.3 (renamed from the contract's
 * original `WalletTransaction` to avoid colliding with the live guest wallet).
 *
 * One append-only row per balance mutation. `balance_after` is stored at write
 * time (§7) inside the same row-locked transaction that mutates the wallet.
 *
 * The `refund_reversal` type is built NOW so adding it later is never a
 * migration against live financial rows (contract §2 / frontend go-ahead §2):
 * its firing action — refunding a completed booking — is deferred pending a
 * product decision, but the enum value ships today at zero cost.
 *
 * Immutability is enforced at the DB level in a follow-up (trigger / grant);
 * the model is also guarded. No `updated_at`: a ledger row is never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('type', ['earning', 'payout', 'refund_reversal', 'adjustment']);
            $table->decimal('amount', 12, 2);        // signed: + credit, − debit
            $table->decimal('balance_after', 12, 2); // available_balance after this row is applied

            $table->string('ref_type', 20);          // booking | payout | manual
            $table->string('ref_id')->nullable();
            $table->string('ref_code')->nullable();   // human-readable, shown in the UI
            $table->string('description')->nullable();

            // Set only for type = adjustment (a manual superadmin correction).
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['partner_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_ledger_entries');
    }
};
