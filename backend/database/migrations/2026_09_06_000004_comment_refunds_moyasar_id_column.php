<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Document, in the schema itself, that `refunds.moyasar_refund_id` holds a
 * PAYMENT id.
 *
 * The name has been wrong since the cancellation engine shipped: Moyasar has no
 * refund object, and `POST /payments/{id}/refund` returns the updated payment,
 * so the id stored is the payment's. Every refund against the same payment
 * therefore carries the SAME value — it is not unique and cannot identify one
 * refund.
 *
 * A note in a document does not reach the person who writes the next query
 * against this column; a comment attached to the column does. Renaming it is
 * deferred to the MOYASAR_WEBHOOK_SECRET rotation window, so that changes
 * touching a live environment land together rather than one at a time.
 */
return new class extends Migration
{
    private const NOTE = 'Holds the Moyasar PAYMENT id, not a refund id — Moyasar has no refund object. NOT unique: all refunds on one payment share it.';

    public function up(): void
    {
        // MySQL only. SQLite has no column comments, and the model accessor
        // carries the same warning for anyone reading the code instead.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE refunds MODIFY moyasar_refund_id VARCHAR(255) NULL COMMENT '".self::NOTE."'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE refunds MODIFY moyasar_refund_id VARCHAR(255) NULL COMMENT ""');
        }
    }
};
