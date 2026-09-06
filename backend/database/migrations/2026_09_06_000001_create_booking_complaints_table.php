<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest complaints against a completed stay — complaints/refunds spec v1.0 §4.1.
 *
 * One complaint per booking (R7), enforced by a unique index rather than by
 * application check: two concurrent submissions would both pass a `exists()`
 * test and the second would create a duplicate the admin queue then shows
 * twice. The unique index makes the race impossible instead of unlikely.
 *
 * `internal_note` is admin-only for the life of the row (R-§5.3, T13). It lives
 * on the same table as `guest_message` deliberately — an admin writes both in
 * one form, and splitting them across tables would not stop a resource from
 * serialising the wrong one. The guard belongs in the resources; this comment
 * exists so nobody adds `internal_note` to a guest payload by reflex.
 *
 * The complaint status is NOT the booking status: the booking stays `completed`
 * throughout (R6). Nothing here widens the bookings enum.
 *
 * No `complaint_penalty` ledger type ships with this (v1.1 D1): the penalty was
 * dropped from Phase A, so `partner_ledger_entries` takes no ALTER at all and
 * the refund debit reuses the `refund_reversal` type that has been sitting
 * unused since August waiting for exactly this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_complaints', function (Blueprint $table) {
            $table->id();

            // Unique: one complaint per booking in v1 (R7).
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();

            // Denormalised from bookings.user_id so the admin list can filter by
            // complainant without a join; validated equal at write time.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('status', [
                'submitted',
                'under_review',
                'approved',            // amount fixed by a superadmin, awaiting finance
                'resolved_refunded',
                'resolved_rejected',
            ])->default('submitted');

            $table->text('description');              // 20–2000 chars, enforced in the request
            $table->boolean('contacted_partner');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Approval is a SEPARATE act from execution (D5). A superadmin fixes
            // the amount here; finance may then execute that number and nothing
            // else. Two actors, two timestamps, so the audit shows who decided
            // what was owed and who moved the money — even when one person
            // currently holds both roles.
            //
            // Halalas, as an integer, deliberately against the decimal-SAR
            // convention used for money columns: this is not a ledger amount,
            // it is a control value compared for EXACT equality against the
            // integer the execute request supplies (T17). Comparing decimals
            // for equality is how a 0.01 drift becomes an authorised payout.
            $table->unsignedBigInteger('approved_refund_halalas')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('internal_note')->nullable(); // ADMIN ONLY — never serialised to guest or partner
            $table->text('guest_message')->nullable(); // shown to the guest with the decision

            $table->timestamps();

            // Drives the admin queue: filter by status, newest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_complaints');
    }
};
