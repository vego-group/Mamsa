<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Refund;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\BookingConfirmed;
use App\Notifications\NewBooking;
use App\Notifications\PaymentAfterCancellation;
use App\Support\Booking\Availability;
use App\Support\OpsAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Turning a successful payment into a confirmed stay.
 *
 * Lifted out of PaymentController when a second caller appeared: the
 * reconciliation job, which asks Moyasar about payments whose webhook never
 * arrived. That job must not re-implement any of this — the locked availability
 * re-check, the restore of a booking the platform itself cancelled, the refusal
 * to resurrect one a guest cancelled, the automatic refund when the nights are
 * gone. A second copy of "confirm a payment" is a second chance to sell the
 * same nights twice.
 *
 * The behaviour is unchanged from the controller version; only its address is.
 */
class BookingPaymentSettler
{
    /** Outcomes of claiming the nights for a payment that just succeeded. */
    private const NIGHTS_HELD = 'held';    // confirmed — the stay is the guest's

    private const PAID_TOO_LATE = 'lost';    // paid, but the nights are gone

    private const NOTHING_TO_DO = 'noop';    // already settled, or not ours to settle

    public function __construct(
        private readonly MoyasarService $moyasar,
        private readonly CancellationPolicyService $cancellationPolicy,
    ) {}

    /**
     * Confirm a paid booking and notify whoever OWNS the unit (in-app + email):
     * a partner listing → its partner owner only; a Mamsa-owned listing → all
     * super admins. Single entry point for every payment success path.
     */
    public function confirm(Booking $booking): void
    {
        // Idempotency: a webhook + redirect can both land here. Freeze + notify once.
        if ($booking->status === Booking::STATUS_CONFIRMED) {
            return;
        }

        $outcome = $this->claimNights($booking);

        if ($outcome === self::PAID_TOO_LATE) {
            $this->refundUnrecoverable($booking->refresh());

            return;
        }

        if ($outcome !== self::NIGHTS_HELD) {
            return;
        }

        $booking->refresh()->loadMissing('unit.owner', 'user', 'payment');

        // Wallet ledger (سجل المعاملات): one signed entry per paid booking.
        // Inside the idempotency guard above, so duplicates are impossible.
        try {
            $booking->user?->walletTransactions()->create([
                'ref_code' => 'PAY-'.now()->format('Y').'-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT),
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => -1 * (float) $booking->total_amount,
                'description' => 'دفع حجز — '.($booking->unit?->unit_name ?? 'وحدة #'.$booking->unit_id),
                'status' => 'completed',
                'booking_id' => $booking->id,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // ledger is informational — never block a paid booking
        }

        // Best-effort: a mail/SMS failure must never break a paid booking.
        try {
            // Booking notifications go to whoever OWNS the unit:
            //  - Partner listing     → the partner (unit owner) only.
            //  - Mamsa-owned listing → all super admins (no external partner, so
            //    the platform's super admins stand in as the owner).
            $unit = $booking->unit;
            $recipients = $unit?->mamsa_owned
                ? User::role('SuperAdmin')->get()
                : collect(array_filter([$unit?->owner]));

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new NewBooking($booking));
            }

            // FR-034 / FR-100: SMS booking confirmation to the guest.
            $booking->user?->notify(new BookingConfirmed($booking));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Take the nights for a paid booking, under the same lock the create takes.
     *
     * The guard this replaced was one line — return early if the booking is
     * already `confirmed` — and it answered the wrong question. `confirmed` is
     * not the only state a payment can arrive into. A booking whose hold
     * expired has been CANCELLED by `bookings:expire-pending`, and cancelling
     * it RELEASED its nights: `Availability` stops counting a cancelled row the
     * moment it is written. So the old code would flip that booking straight
     * back to `confirmed` without asking whether the nights were still there —
     * and if another guest had taken them in between, the platform had sold the
     * same nights twice and told nobody.
     *
     * The window is not theoretical. The expiry job skips bookings whose
     * payment is `paid` or whose Moyasar id was touched in the last 15 minutes,
     * which narrows it a great deal, but a payment older than that and not yet
     * `paid` is cancelled and can still be confirmed by a webhook afterwards —
     * a slow 3-DS challenge, or a gateway retry.
     *
     * Lock order is `units` then `bookings`, matching BookingController exactly.
     * Only ONE unit row is locked here (the apartment is already allocated), so
     * a create holding several siblings and waiting on this one cannot deadlock:
     * this path never waits on a second unit.
     */
    private function claimNights(Booking $booking): string
    {
        return DB::transaction(function () use ($booking) {
            Unit::whereKey($booking->unit_id)->lockForUpdate()->first();

            $fresh = Booking::whereKey($booking->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status === Booking::STATUS_CONFIRMED) {
                return self::NOTHING_TO_DO;
            }

            // The ordinary path: the booking still holds its own claim on the
            // nights, made under this same lock when it was created. Re-checking
            // availability here would find ITSELF and refuse.
            if ($fresh->status === Booking::STATUS_PENDING) {
                $this->markConfirmed($fresh, restore: false);

                return self::NIGHTS_HELD;
            }

            if ($fresh->status !== Booking::STATUS_CANCELLED) {
                return self::NOTHING_TO_DO;
            }

            // Only a cancellation the PLATFORM made for non-payment may be
            // undone. A guest or partner who cancelled on purpose has decided
            // something, and a late webhook is not permission to reverse it —
            // that money gets refunded instead.
            if ($fresh->cancelled_by !== 'system') {
                return self::PAID_TOO_LATE;
            }

            if (! $this->nightsStillFree($fresh)) {
                return self::PAID_TOO_LATE;
            }

            $this->markConfirmed($fresh, restore: true);

            return self::NIGHTS_HELD;
        });
    }

    /**
     * Are this cancelled booking's dates still free — ignoring itself?
     *
     * `whereKeyNot` is belt-and-braces: a cancelled row is already outside
     * Availability::BLOCKING_STATUSES, so it cannot count itself today. It is
     * there so that widening those statuses later cannot silently turn this
     * check into "the nights are taken — by me".
     *
     * Blocked dates are checked too, for the same reason the create checks
     * them: a partner may have closed the unit while the payment was in flight.
     */
    private function nightsStillFree(Booking $booking): bool
    {
        $start = $booking->start_date instanceof \DateTimeInterface
            ? $booking->start_date->format('Y-m-d') : (string) $booking->start_date;
        $end = $booking->end_date instanceof \DateTimeInterface
            ? $booking->end_date->format('Y-m-d') : (string) $booking->end_date;

        $taken = Availability::conflictingBookings((int) $booking->unit_id, $start, $end)
            ->whereKeyNot($booking->id)
            ->exists();

        if ($taken) {
            return false;
        }

        return ! $booking->loadMissing('unit')->unit
            ?->blockedDates()->overlapping($start, $end)->exists();
    }

    /** Confirm, freezing the refund terms; clear the cancellation when undoing one. */
    private function markConfirmed(Booking $booking, bool $restore): void
    {
        $booking->loadMissing('unit.cancellationPolicy.tiers');

        // FR-036: freeze the cancellation policy onto the booking at payment
        // time so later partner edits never alter this booking's refund terms.
        $booking->update([
            'status' => Booking::STATUS_CONFIRMED,
            'cancellation_snapshot' => $this->cancellationPolicy->snapshotForBooking($booking),
        ] + ($restore ? [
            // A booking that is confirmed and still carries a cancellation
            // reason reads as cancelled on every screen that shows one.
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ] : []));
    }

    /**
     * The guest paid for nights the platform can no longer give them.
     *
     * Refund in full and tell a human. Automatic money back is the correct
     * first move, but it is not the whole remedy: this guest was told their
     * booking was cancelled and then watched a charge appear, so somebody has
     * to contact them. That is what the alert is for.
     *
     * Idempotent on `idempotency_key`, because the webhook and the browser
     * redirect both reach this and a double refund is worse than none.
     */
    private function refundUnrecoverable(Booking $booking): void
    {
        $key = 'late-payment:'.$booking->id;

        if (Refund::where('idempotency_key', $key)->exists()) {
            return;
        }

        $booking->loadMissing('payment', 'user', 'unit');

        $payment = $booking->payment;
        $amount = (float) $booking->total_amount;
        $gateway = null;
        $outcome = 'simulated';

        if ($payment?->moyasar_id) {
            try {
                $gateway = $this->moyasar->refund($payment->moyasar_id, (int) round($amount * 100));
                $outcome = 'gateway';
            } catch (\Throwable $e) {
                // Do NOT rethrow: the booking stays cancelled either way, and
                // losing the alert would leave a charged guest with nobody
                // knowing. The failed row plus the alert IS the handling.
                $outcome = 'failed';

                Log::critical('Late payment: automatic refund failed at the gateway — REFUND BY HAND', [
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $refund = $booking->refunds()->create([
                'payment_id' => $payment?->id,
                'type' => Refund::TYPE_REFUND,
                'amount' => $amount,
                'refund_percent' => 100,
                'tier_label' => 'دفعة بعد انتهاء المهلة',
                // `reason` is an enum of complaint|host_cancellation|other, so
                // this is `other` with the detail in `tier_label` rather than a
                // new member — an unknown enum value is silently truncated by
                // MySQL and takes the whole write down with it.
                'reason' => Refund::REASON_OTHER,
                'status' => match ($outcome) {
                    'gateway' => Refund::STATUS_PENDING,   // webhook settles it
                    'failed' => Refund::STATUS_FAILED,
                    default => Refund::STATUS_SUCCEEDED, // nothing to call
                },
                'failure_reason' => $outcome === 'failed' ? 'gateway refund threw' : null,
                'idempotency_key' => $key,
                'moyasar_refund_id' => $gateway['id'] ?? null,
                'moyasar_response' => $gateway ?? ['simulated' => true],
            ]);

            if ($outcome !== 'failed' && $payment) {
                $payment->increment('refunded_amount', $amount);
            }

            if ($outcome !== 'failed') {
                $booking->user?->walletTransactions()->create([
                    'ref_code' => 'REF-'.now()->format('Y').'-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT),
                    'type' => WalletTransaction::TYPE_REFUND,
                    'amount' => $amount,
                    'description' => 'استرداد كامل — وصلت الدفعة بعد إلغاء الحجز',
                    'status' => 'completed',
                    'booking_id' => $booking->id,
                    'occurred_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        OpsAlert::raise(new PaymentAfterCancellation($booking, $amount, $outcome));
    }
}
