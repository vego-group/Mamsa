<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the LIVE `refunds` table to carry complaint refunds — spec v1.0 §4.3,
 * which asked for a second table named `booking_refunds`. It is not built.
 *
 * `refunds` already exists (2026_06_24) and is already the general refund
 * record the spec says it wants: the admin cancellations screen reads it, the
 * Moyasar settlement webhook writes it, and `payments.refunded_amount`
 * accumulates against it. A parallel table would mean `already_refunded` is
 * computed from one ledger while the cancellation path moves the other — and
 * the two disagreeing is not a reporting bug, it is an over-refund: a booking
 * partly refunded on cancellation could be refunded again in full here.
 *
 * So the columns land on the existing row instead. Everything a complaint
 * refund needs that a cancellation refund did not:
 *
 *   - the split, frozen at execution time (Booking::splitRefund)
 *   - an idempotency key, so a retried request cannot pay twice
 *   - who executed it, and why
 *   - a manual-transfer escape hatch for when the gateway refuses an old payment
 *   - the ZATCA credit-note fields, empty until the tax registration lands
 *
 * The split columns are NULLABLE because the rows already in this table are
 * cancellation refunds written before the split existed. Backfilling them
 * would mean inventing a commission rate for historical rows — the exact
 * restatement the frozen-rate rule exists to prevent. NULL reads as "this
 * refund predates the split", which is true and checkable; a computed value
 * would read as fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('complaint_id')
                ->nullable()
                ->constrained('booking_complaints')
                ->nullOnDelete();

            // Why this refund happened. Existing rows are cancellation-engine
            // refunds; 'other' is the honest label for them — the table does
            // not record whether the guest or the host cancelled, and guessing
            // would put a wrong reason on real financial history.
            $table->enum('reason', ['complaint', 'host_cancellation', 'other'])
                ->default('other');

            // The split, frozen at execution. decimal(10,2) matches `amount`.
            // Invariant, asserted in code and in the regression test:
            //   amount_commission + amount_partner + amount_vat === amount
            $table->decimal('amount_vat', 10, 2)->nullable();
            $table->decimal('amount_commission', 10, 2)->nullable();
            $table->decimal('amount_partner', 10, 2)->nullable();

            // Unique, nullable: both MySQL and SQLite allow repeated NULLs in a
            // unique index, so historical rows without a key stay legal while
            // no two new refunds can share one.
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->text('failure_reason')->nullable();

            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            // Set only when the gateway could not take the refund and finance
            // returned the money by bank transfer. Its presence is what makes a
            // succeeded refund with moyasar_refund_id = NULL auditable rather
            // than suspicious.
            $table->string('manual_transfer_reference')->nullable();

            // ZATCA credit note — shipped empty on purpose (spec §8), so the
            // legal data arriving later is an UPDATE, not a migration against
            // live financial rows.
            $table->string('credit_note_number')->nullable();
            $table->text('credit_note_qr')->nullable();

            $table->index(['complaint_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['complaint_id', 'status']);
            $table->dropConstrainedForeignId('complaint_id');
            $table->dropConstrainedForeignId('initiated_by');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'reason',
                'amount_vat',
                'amount_commission',
                'amount_partner',
                'idempotency_key',
                'failure_reason',
                'manual_transfer_reference',
                'credit_note_number',
                'credit_note_qr',
            ]);
        });
    }
};
