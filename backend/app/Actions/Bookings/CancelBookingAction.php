<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\DTOs\RefundQuote;
use App\Exceptions\CancellationException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Services\CancellationPolicyService;
use App\Services\MoyasarService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates booking cancellation end-to-end — SRS 2.3.2 (FR-044→047).
 *
 * Flow: guard state → quote refund from the frozen snapshot → run the gateway
 * refund/void → persist refund + free the unit + audit, all atomically → notify
 * the guest. The external Moyasar call happens BEFORE the DB transaction so a
 * gateway failure leaves the booking untouched, and the (tiny, local) DB write
 * cannot hold a transaction open across a network call.
 */
class CancelBookingAction
{
    /** Moyasar void is only worthwhile within ~2h of capture — SRS 2.3.3. */
    private const VOID_WINDOW_MINUTES = 120;

    public function __construct(
        private readonly CancellationPolicyService $policy,
        private readonly MoyasarService $moyasar,
    ) {}

    public function execute(Booking $booking, ?User $actor = null, ?string $reason = null): RefundQuote
    {
        // Terminal states cannot be cancelled.
        if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED], true)) {
            throw new CancellationException('لا يمكن إلغاء هذا الحجز');
        }

        // Unpaid booking: release it immediately, no money involved.
        if ($booking->status === Booking::STATUS_PENDING) {
            DB::transaction(fn () => $this->markCancelled($booking, null, $actor, $reason));

            return new RefundQuote(true, 0.0, 0, null, 0);
        }

        // Confirmed booking: evaluate the frozen policy snapshot.
        $quote = $this->policy->quote($booking);

        if (! $quote->cancellable) {
            throw new CancellationException($quote->reason ?? 'لا يمكن إلغاء هذا الحجز');
        }

        $payment      = $booking->payment;
        $gatewayResult = null;
        $useVoid       = false;

        if ($quote->refundAmount > 0 && $payment) {
            [$gatewayResult, $useVoid] = $this->runGatewayRefund($payment, $quote);
        }

        DB::transaction(function () use ($booking, $payment, $quote, $gatewayResult, $useVoid, $actor, $reason) {
            if ($quote->refundAmount > 0 && $payment) {
                $this->recordRefund($booking, $payment, $quote, $gatewayResult, $useVoid, $actor);
            }

            $this->markCancelled($booking, $quote, $actor, $reason);
        });

        $this->notifyGuest($booking, $quote);
        $this->notifyPartner($booking, $quote);

        return $quote;
    }

    /**
     * Execute the refund or void against Moyasar (skipped in test mode).
     *
     * @return array{0: array<string, mixed>, 1: bool}  [gateway response, usedVoid]
     */
    private function runGatewayRefund(Payment $payment, RefundQuote $quote): array
    {
        // Void only releases the FULL amount and only shortly after capture.
        $isFull = $quote->refundPercent === 100
            && abs($quote->refundAmount - $payment->refundableAmount()) < 0.01;
        $withinVoidWindow = $payment->paid_at
            && $payment->paid_at->diffInMinutes(now()) <= self::VOID_WINDOW_MINUTES;
        $useVoid = $isFull && $withinVoidWindow;

        if ($this->isTestMode() || ! $payment->moyasar_id) {
            return [['test' => true, 'simulated' => true], $useVoid];
        }

        try {
            $response = $useVoid
                ? $this->moyasar->void($payment->moyasar_id)
                : $this->moyasar->refund($payment->moyasar_id, (int) round($quote->refundAmount * 100));
        } catch (\Throwable $e) {
            Log::error('Cancellation gateway refund failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
            throw new CancellationException('تعذّر تنفيذ الاسترداد عبر بوابة الدفع، حاول لاحقاً');
        }

        // Persist the gateway id immediately so a later DB hiccup is still traceable.
        Log::info('Cancellation gateway refund ok', [
            'payment_id'  => $payment->id,
            'gateway_id'  => $response['id'] ?? null,
            'used_void'   => $useVoid,
        ]);

        return [$response, $useVoid];
    }

    /** @param array<string, mixed> $gatewayResult */
    private function recordRefund(
        Booking $booking,
        Payment $payment,
        RefundQuote $quote,
        array $gatewayResult,
        bool $useVoid,
        ?User $actor,
    ): void {
        $refund = $booking->refunds()->create([
            'payment_id'        => $payment->id,
            'type'              => $useVoid ? Refund::TYPE_VOID : Refund::TYPE_REFUND,
            'amount'            => $quote->refundAmount,
            'refund_percent'    => $quote->refundPercent,
            'tier_label'        => $quote->tierLabel,
            'status'            => 'succeeded',
            'moyasar_refund_id' => $gatewayResult['id'] ?? null,
            'moyasar_response'  => $gatewayResult,
        ]);

        $payment->increment('refunded_amount', $quote->refundAmount);

        // Wallet ledger (سجل المعاملات): refunds show as positive entries.
        try {
            $booking->user?->walletTransactions()->create([
                'ref_code'    => 'REF-'.now()->format('Y').'-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT),
                'type'        => \App\Models\WalletTransaction::TYPE_REFUND,
                'amount'      => (float) $quote->refundAmount,
                'description' => 'استرداد من حجز — '.($booking->unit?->unit_name ?? 'وحدة #'.$booking->unit_id),
                'status'      => 'completed',
                'booking_id'  => $booking->id,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // informational — never block a cancellation
        }

        AuditLog::record($refund, 'refund.executed', null, [
            'type'    => $refund->type,
            'amount'  => $quote->refundAmount,
            'percent' => $quote->refundPercent,
        ], $actor?->id);
    }

    private function markCancelled(Booking $booking, ?RefundQuote $quote, ?User $actor, ?string $reason = null): void
    {
        $before = ['status' => $booking->status];

        $booking->update([
            'status'              => Booking::STATUS_CANCELLED,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
            'cancelled_by'        => $this->resolveActor($booking, $actor),
        ]);

        // The unit's dates free up automatically: availability is derived from
        // bookings in (pending, confirmed), which now excludes this one (FR-046).
        AuditLog::record($booking, 'booking.cancelled', $before, [
            'status'         => Booking::STATUS_CANCELLED,
            'refund_amount'  => $quote?->refundAmount ?? 0,
            'refund_percent' => $quote?->refundPercent ?? 0,
        ], $actor?->id);
    }

    /**
     * Classify who triggered the cancellation for the "ملغي" card.
     * The booking owner → customer; any other authenticated actor → admin;
     * no actor (e.g. an automated job) → system.
     */
    private function resolveActor(Booking $booking, ?User $actor): string
    {
        return match (true) {
            $actor === null                  => 'system',
            $actor->id === $booking->user_id => 'customer',
            default                          => 'admin',
        };
    }

    private function notifyGuest(Booking $booking, RefundQuote $quote): void
    {
        // A messaging failure must never undo a completed cancellation.
        try {
            $booking->loadMissing('user', 'unit');
            $booking->user?->notify(new BookingCancelled($booking, $quote->refundAmount));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyPartner(Booking $booking, RefundQuote $quote): void
    {
        try {
            $booking->loadMissing('unit.owner');
            $booking->unit?->owner?->notify(
                new \App\Notifications\GuestCancelledBooking($booking, $quote->refundAmount),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function isTestMode(): bool
    {
        return blank(config('moyasar.secret_key'));
    }
}
