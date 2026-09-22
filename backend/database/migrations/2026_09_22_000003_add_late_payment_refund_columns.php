<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three refund columns the LATE-PAYMENT path needs, without the rest of
 * the complaints work.
 *
 * `BookingPaymentSettler::refundUnrecoverable()` — the path that refunds a
 * guest whose payment landed after their booking was cancelled — writes
 * `reason`, `idempotency_key` and `failure_reason`, and reads
 * `Refund::REASON_OTHER` and the `STATUS_*` constants. All of them arrived
 * with the complaints/refunds feature on 2026-09-06. The settler was deployed
 * to production; the complaints work was not. So on production that method
 * throws on its first line (`Refund::where('idempotency_key', …)` — unknown
 * column), which means the guest is not refunded, the operations alert never
 * fires, and the payment callback returns 500. Found by the branch/production
 * parity sweep on 2026-09-22, not by anything failing: the trigger is a
 * payment arriving after a cancellation, which has not happened yet.
 *
 * This adds exactly those three, with the same definitions the complaints
 * migration uses, so the two agree wherever both eventually run. Every step is
 * guarded by hasColumn: on staging, where the complaints migration already
 * ran, this is a no-op; on production it is the whole fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            if (! Schema::hasColumn('refunds', 'reason')) {
                // Existing rows are cancellation-engine refunds; 'other' is the
                // honest label — the table does not record whether the guest or
                // the host cancelled, and guessing would put a wrong reason on
                // real financial history.
                $table->enum('reason', ['complaint', 'host_cancellation', 'other'])->default('other');
            }

            if (! Schema::hasColumn('refunds', 'idempotency_key')) {
                // Unique, nullable: both MySQL and SQLite allow repeated NULLs
                // in a unique index, so historical rows without a key stay
                // legal while no two new refunds can share one.
                $table->string('idempotency_key', 64)->nullable()->unique();
            }

            if (! Schema::hasColumn('refunds', 'failure_reason')) {
                $table->text('failure_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: dropping these would take the late
        // payment guard with them, and the rows they hold are financial.
    }
};
