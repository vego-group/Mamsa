<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RefundInFlightException;
use App\Models\Booking;
use App\Models\BookingComplaint;
use App\Models\PartnerLedgerEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\ComplaintPartnerDebited;
use App\Notifications\ComplaintRefundFailed;
use App\Notifications\ComplaintRefundSettled;
use App\Notifications\RefundSettlementAmbiguous;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Executing and settling a complaint refund — complaints/refunds spec v1.2.
 *
 * Split across two moments on purpose, because the money moves at the second
 * one:
 *
 *   execute()  the admin acts. Validates, splits, writes a `pending` refund,
 *              asks the gateway. Writes NO ledger entry.
 *   settle()   the gateway confirms. Debits the partner, closes the complaint.
 *
 * A 200 from Moyasar's refund endpoint is ACCEPTANCE, not settlement — the
 * cancellation path already knows this ('Gateway-accepted refunds settle via
 * webhook'). Posting the partner debit on that 200 would take money from a
 * partner for a refund that can still fail, and the ledger is append-only, so
 * the correction would be a second entry rather than an edit. Hence R10, and
 * hence the split.
 */
class ComplaintRefundService
{
    /**
     * Above this many pending refunds on one payment, subset enumeration is
     * abandoned and the event is treated as unattributable. Real payments carry
     * one or two; this is a guard against 2^n, not a business rule.
     */
    private const MAX_SUBSET_ROWS = 16;

    public function __construct(
        private readonly MoyasarService $moyasar,
        private readonly PartnerWalletService $wallet,
    ) {}

    /**
     * Execute an approved refund. Returns the `pending` (or, with no gateway,
     * already-settled) refund row.
     *
     * @throws \RuntimeException with a guest-safe Arabic message on refusal
     */
    public function execute(
        BookingComplaint $complaint,
        int $amountHalalas,
        User $actor,
        string $idempotencyKey,
    ): Refund {
        // Replaying a key returns the original outcome untouched — the caller
        // answers 200 with it. Checked before the transaction because a retry
        // must be cheap, and re-checked inside it against the unique index,
        // which is what actually makes two simultaneous submissions safe.
        if ($existing = Refund::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $refund = DB::transaction(function () use ($complaint, $amountHalalas, $actor, $idempotencyKey) {
            /** @var Booking $booking */
            $booking = Booking::query()
                ->whereKey($complaint->booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            // One refund at a time per complaint. Checked INSIDE the booking's
            // row lock, so two simultaneous requests serialise and the second
            // sees the first.
            //
            // The idempotency key does not cover this: it catches the same
            // request twice, and this is a different request with a fresh key.
            // A successful execute leaves the refund `pending` and the complaint
            // `approved` — the complaint is only closed on settlement — so the
            // screen still offers an execute button while money is in flight,
            // and settlement can be an hour away if the webhook is late.
            // Without this, a second click refunds the guest twice.
            //
            // ── SCOPE, and what it rests on ──
            // This guard is keyed on complaint_id; the ceiling below is keyed on
            // booking_id. That division is only safe because the two refund
            // paths can never meet on one booking:
            //
            //   cancellation refunds  → a `confirmed` booking
            //                           (CancelBookingAction refuses `completed`;
            //                            HostCancelBookingAction requires `confirmed`)
            //   complaint refunds     → a `completed` booking
            //                           (the complaint endpoint refuses anything else)
            //
            // So a booking cannot hold both kinds at once, and a per-complaint
            // guard is sufficient to stop double execution.
            //
            // That booking-status constraint is therefore LOAD-BEARING, not
            // incidental. If anyone later allows a cancellation refund on a
            // `completed` booking, or a complaint on a cancelled one, the two
            // paths can race for the same ceiling and neither guard will see the
            // other. Widening either status set means revisiting this — a
            // booking-level lock on refund creation, rather than a
            // complaint-level one.
            $inFlight = Refund::where('complaint_id', $complaint->id)
                ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_SUCCEEDED])
                ->first();

            if ($inFlight) {
                throw new RefundInFlightException($inFlight->id);
            }

            // What is already spoken for. `succeeded` is money returned;
            // `pending` is money the gateway has accepted and not yet settled.
            // Both are committed — leaving pending out is what let a second
            // execute pass this check while the first was still in flight.
            // `failed` is excluded: it returned nothing.
            $committedHalalas = (int) round(
                (float) Refund::where('booking_id', $booking->id)
                    ->whereIn('status', [Refund::STATUS_SUCCEEDED, Refund::STATUS_PENDING])
                    ->sum('amount') * 100
            );

            $grossHalalas = (int) round((float) $booking->total_amount * 100);
            $refundable   = $grossHalalas - $committedHalalas;

            if ($amountHalalas <= 0 || $amountHalalas > $refundable) {
                throw new \RuntimeException('المبلغ المطلوب أكبر من المتاح للاسترداد على هذا الحجز');
            }

            // `refunds.payment_id` is NOT NULL — the table has always described a
            // movement against a specific captured payment. Without one there is
            // nothing to refund against, and letting it reach the insert would
            // surface as a constraint violation rather than as an answer.
            if (! $booking->payment) {
                throw new \RuntimeException('لا توجد عملية دفع مرتبطة بهذا الحجز');
            }

            // The rate comes off the booking, never from config (B1).
            $split = $booking->splitRefund($amountHalalas / 100);

            // Halalas first, partner share as the REMAINDER (D3): every rounding
            // difference lands in one place and the integers add up exactly.
            // Deriving all three independently is how an invariant test starts
            // failing by a halala on unlucky amounts.
            $vatHalalas        = (int) round($split['vat'] * 100);
            $commissionHalalas = (int) round($split['commission_amount'] * 100);
            $partnerHalalas    = $amountHalalas - $vatHalalas - $commissionHalalas;

            return Refund::create([
                'booking_id'        => $booking->id,
                'payment_id'        => $booking->payment->id,
                'complaint_id'      => $complaint->id,
                'reason'            => Refund::REASON_COMPLAINT,
                'type'              => Refund::TYPE_REFUND,
                'amount'            => $amountHalalas / 100,
                'amount_vat'        => $vatHalalas / 100,
                'amount_commission' => $commissionHalalas / 100,
                'amount_partner'    => $partnerHalalas / 100,
                // Percent of the booking this refund represents. Carried for the
                // cancellation screens, which render the column for every row.
                'refund_percent'    => $grossHalalas > 0
                    ? round($amountHalalas / $grossHalalas * 100, 2)
                    : 0,
                'status'            => Refund::STATUS_PENDING,
                'idempotency_key'   => $idempotencyKey,
                'initiated_by'      => $actor->id,
            ]);
        });

        return $this->send($refund);
    }

    /**
     * Hand the refund to the gateway. Failure marks the row and returns the
     * complaint to `approved` — the approval still stands, a gateway rejection
     * says nothing about whether the guest was owed money (v1.2 §1).
     */
    private function send(Refund $refund): Refund
    {
        $payment = $refund->payment;

        // No gateway configured, or a booking that never reached one: settle
        // immediately rather than leaving a row that nothing will ever confirm.
        // This is also what makes the flow testable on staging, which has no
        // webhook registration of its own — Moyasar's webhook registry is
        // account-level, so staging cannot be given one in isolation.
        $simulated = blank(config('moyasar.secret_key')) || blank($payment?->moyasar_id);

        if ($simulated) {
            $refund->update([
                'status'           => Refund::STATUS_SUCCEEDED,
                'moyasar_response' => ['simulated' => true],
            ]);

            $this->settle($refund->fresh());

            return $refund->fresh();
        }

        try {
            $gateway = $this->moyasar->refund($payment->moyasar_id, (int) round($refund->amount * 100));
        } catch (\Throwable $e) {
            report($e);

            $refund->update([
                'status'         => Refund::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
            ]);

            $refund->complaint?->update([
                'status' => BookingComplaint::STATUS_AFTER_FAILED_REFUND,
            ]);

            $this->alert(new ComplaintRefundFailed($refund->fresh()));

            throw new \RuntimeException('تعذّر تنفيذ الاسترداد عبر بوابة الدفع، حاول لاحقاً');
        }

        // Accepted, NOT settled. Stays pending until the webhook confirms.
        $refund->update([
            'moyasar_refund_id' => $gateway['id'] ?? null,
            'moyasar_response'  => $gateway,
        ]);

        return $refund->fresh();
    }

    /**
     * Decide which pending refunds a settlement event actually covers, and flip
     * exactly those.
     *
     * Moyasar has no refund object. `POST /payments/{id}/refund` returns the
     * updated PAYMENT — verified against three stored gateway responses whose
     * `moyasar_refund_id` is byte-identical to the payment's own id — so the
     * event carries no per-refund identifier and cannot say which refund it
     * means. The only signal is the payment's cumulative `refunded` total.
     *
     * So: subtract what is already settled, then walk the pending rows oldest
     * first, taking each one that fits inside the remainder. If the remainder
     * does not land exactly on zero, the event cannot be attributed and NOTHING
     * is settled.
     *
     * Refusing to guess is the whole point. Settling the wrong row credits a
     * refund that never happened and debits a partner for money the guest never
     * received — and in an append-only ledger that is corrected by a second
     * entry, not by an edit. A missing entry can be added once a human looks;
     * a wrong one is permanent.
     *
     * @return Collection<int, \App\Models\Refund> the rows this call flipped
     */
    public function settlePending(Payment $payment, int $gatewayRefundedHalalas): Collection
    {
        $outcome = DB::transaction(function () use ($payment, $gatewayRefundedHalalas) {
            $pending = $payment->refunds()
                ->where('status', Refund::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($pending->isEmpty()) {
                // A replay, or the same event through the second registration.
                return ['settled' => collect(), 'unexplained' => 0, 'pending' => $pending];
            }

            $alreadyHalalas = (int) round(
                (float) $payment->refunds()->where('status', Refund::STATUS_SUCCEEDED)->sum('amount') * 100
            );

            $unaccounted = $gatewayRefundedHalalas - $alreadyHalalas;

            if ($unaccounted <= 0) {
                // The gateway reports nothing new. Not an error — an earlier
                // delivery already accounted for everything.
                return ['settled' => collect(), 'unexplained' => 0, 'pending' => $pending];
            }

            // Which rows the event covers must be the ONLY answer, not merely
            // an answer. Walking oldest-first and stopping at zero finds *a*
            // subset — with rows of 100, 200 and 300 and a remainder of 300 it
            // takes {100,200} and settles two refunds, when {300} sums to the
            // same and may be the one that actually cleared. Two wrong ledger
            // debits, and the right row left pending: exactly the failure this
            // whole mechanism exists to prevent.
            //
            // So enumerate every subset and settle only when exactly one sums
            // to the remainder. Pending rows on a single payment number a
            // handful, so the cost is nothing; the cap exists so a pathological
            // case degrades to an alert rather than to 2^n work.
            $amounts = $pending->map(fn ($r) => (int) round((float) $r->amount * 100))->values()->all();
            $count   = count($amounts);

            if ($count > self::MAX_SUBSET_ROWS) {
                return ['settled' => collect(), 'unexplained' => $unaccounted, 'pending' => $pending];
            }

            $match = null;

            for ($mask = 1; $mask < (1 << $count); $mask++) {
                $sum = 0;

                for ($i = 0; $i < $count; $i++) {
                    if ($mask & (1 << $i)) {
                        $sum += $amounts[$i];
                    }
                }

                if ($sum !== $unaccounted) {
                    continue;
                }

                if ($match !== null) {
                    // A second subset sums to the same total. Which one settled
                    // is unknowable from what the gateway tells us.
                    $match = null;
                    break;
                }

                $match = $mask;
            }

            if ($match === null) {
                return ['settled' => collect(), 'unexplained' => $unaccounted, 'pending' => $pending];
            }

            $settle = $pending->values()->filter(fn ($r, $i) => (bool) ($match & (1 << $i)))->values();

            Refund::whereIn('id', $settle->pluck('id'))
                ->update(['status' => Refund::STATUS_SUCCEEDED]);

            return ['settled' => $settle, 'unexplained' => 0, 'pending' => $pending];
        });

        if ($outcome['unexplained'] !== 0) {
            Log::error('Moyasar settlement could not be attributed to pending refunds', [
                'payment_id'  => $payment->id,
                'gateway'     => $gatewayRefundedHalalas,
                'unexplained' => $outcome['unexplained'],
            ]);

            $this->alert(new RefundSettlementAmbiguous(
                $payment,
                $gatewayRefundedHalalas,
                $outcome['unexplained'],
                $outcome['pending']->map(fn ($r) => ['id' => $r->id, 'amount' => (float) $r->amount])->all(),
            ));
        }

        return $outcome['settled'];
    }

    /**
     * The settlement half: the only place a complaint refund touches the ledger.
     *
     * Called from the Moyasar webhook once the refund is confirmed, and from
     * the simulated path above. Idempotent by construction — the caller flips
     * `pending` → `succeeded` first and passes only rows it actually flipped,
     * so a replayed webhook finds nothing to hand over (T16).
     */
    public function settle(Refund $refund): void
    {
        DB::transaction(function () use ($refund) {
            $booking = $refund->booking?->loadMissing('unit');
            $unit    = $booking?->unit;

            // D4: a Mamsa-owned listing has no partner to debit. `units.user_id`
            // on one of those is the ADMIN who created it, so posting here would
            // debit an employee's wallet for a refund on a platform-owned unit.
            //
            // The guard is explicit rather than leaning on partner_share being
            // 0.00 — which it is today, because those bookings freeze
            // commission_rate at 1.0. That is a second fact that happens to
            // agree; the day it stops agreeing, an implicit guard fails silently
            // and an explicit one keeps working.
            $mamsaOwned = (bool) ($unit?->mamsa_owned);
            $partnerId  = $unit?->user_id;
            $share      = round((float) ($refund->amount_partner ?? 0), 2);

            if (! $mamsaOwned && $partnerId && $share > 0) {
                $this->wallet->post(
                    partnerUserId: $partnerId,
                    // The type built for this in August and never written until
                    // now. A complaint refund IS a refund reversal; a second
                    // name for it would split one movement across two labels.
                    type: PartnerLedgerEntry::TYPE_REFUND_REVERSAL,
                    amount: -1 * $share,
                    refType: 'refund',
                    refId: (string) $refund->id,
                    refCode: $booking->code ?: (string) $booking->id,
                    description: 'خصم بسبب شكوى على الحجز '.($booking->code ?: $booking->id),
                );
            }

            $refund->complaint?->update([
                'status' => BookingComplaint::STATUS_RESOLVED_REFUNDED,
            ]);

            Log::info('Complaint refund settled', [
                'refund_id'    => $refund->id,
                'complaint_id' => $refund->complaint_id,
                'partner_debit' => $mamsaOwned ? 'skipped (mamsa-owned)' : $share,
            ]);
        });

        // After the commit, never inside it: a mail or SMS failure must not roll
        // back a settled refund, and production sends synchronously.
        $this->notifySettled($refund->fresh());
    }

    private function notifySettled(Refund $refund): void
    {
        try {
            $booking = $refund->booking?->loadMissing('user', 'unit.owner');

            $booking?->user?->notify(new ComplaintRefundSettled($refund));

            $share = round((float) ($refund->amount_partner ?? 0), 2);

            if ($share > 0 && ! ($booking?->unit?->mamsa_owned) && $booking?->unit?->owner) {
                $booking->unit->owner->notify(new ComplaintPartnerDebited($refund));
            }
        } catch (\Throwable $e) {
            report($e); // a notification failure must never unsettle a refund
        }
    }

    /** Route an operational alert to the configured recipients (G1). */
    private function alert(object $notification): void
    {
        try {
            $recipients = config('complaints.alert_recipients');

            if (! empty($recipients)) {
                Notification::route('mail', $recipients)->notify($notification);

                return;
            }

            // Empty config degrades to today's behaviour rather than to silence.
            $admins = User::role('SuperAdmin')->where('is_active', true)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Public so the reconciliation command can raise the same alert. */
    public function raise(object $notification): void
    {
        $this->alert($notification);
    }
}
