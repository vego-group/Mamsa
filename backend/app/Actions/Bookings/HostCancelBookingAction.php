<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Exceptions\DashboardException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\UnitBlockedDate;
use App\Models\User;
use App\Notifications\HostCancellation;
use App\Services\MoyasarService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Partner "host-cancel" (contract §6.1) — distinct from the guest policy-based
 * cancellation. Locked business rules #4: refund is 100% of the FULL total
 * (partner forfeits their share, Mamsa forfeits its commission), automatic via
 * Moyasar, no admin approval. Atomic + idempotent.
 */
class HostCancelBookingAction
{
    public function __construct(private readonly MoyasarService $moyasar) {}

    public function execute(Booking $booking, User $partner, string $reason, ?string $idempotencyKey = null): Booking
    {
        // Idempotency: a duplicate key (double-click) returns the already
        // cancelled booking without a second refund.
        $lockKey = $idempotencyKey ? "host-cancel:{$booking->id}:{$idempotencyKey}" : null;
        if ($lockKey && ! Cache::add($lockKey, 1, now()->addHours(24))) {
            return $booking->fresh(['unit.images', 'user', 'payment', 'refunds']);
        }

        try {
            // §6.1 preconditions.
            if ($booking->status !== Booking::STATUS_CONFIRMED) {
                throw new DashboardException('BOOKING_NOT_CANCELLABLE', 'لا يمكن إلغاء هذا الحجز', 409);
            }

            $checkin = $booking->start_date->copy()->setTimeFromTimeString(
                $booking->unit?->checkin_time ? substr((string) $booking->unit->checkin_time, 0, 8) : '15:00:00',
            );
            if ($checkin->isPast()) {
                throw new DashboardException('CHECKIN_PASSED', 'لا يمكن إلغاء حجز بدأ موعد تسجيل دخوله', 409);
            }

            $payment = $booking->payment;
            $refundTotal = (float) $booking->total_amount;

            // Run the gateway refund BEFORE the DB transaction (network call
            // outside the tx). Skipped in test mode or with no captured payment.
            $gatewayResult = null;
            if ($payment && $payment->moyasar_id && ! $this->isTestMode() && $payment->refundableAmount() > 0) {
                $gatewayResult = $this->refundGateway($payment, $refundTotal);
            }

            DB::transaction(function () use ($booking, $partner, $reason, $payment, $refundTotal, $gatewayResult) {
                $booking->update([
                    'status'              => Booking::STATUS_CANCELLED,
                    'cancelled_at'        => now(),
                    'cancelled_by'        => 'partner',
                    'cancellation_reason' => $reason,
                ]);

                if ($payment && $refundTotal > 0) {
                    $this->recordRefund($booking, $payment, $refundTotal, $gatewayResult);
                }

                // §6.1.4 — block the freed dates so they aren't instantly rebooked.
                $booking->unit?->blockedDates()->create([
                    'start_date' => $booking->start_date->toDateString(),
                    'end_date'   => $booking->end_date->toDateString(),
                    'source'     => UnitBlockedDate::SOURCE_MANUAL,
                    'note'       => 'إلغاء المضيف — '.\Illuminate\Support\Str::limit($reason, 100),
                ]);

                AuditLog::record($booking, 'booking.host_cancelled', ['status' => Booking::STATUS_CONFIRMED], [
                    'status' => Booking::STATUS_CANCELLED,
                    'refund' => $refundTotal,
                ], $partner->id);
            });

            $this->notifyGuest($booking, $refundTotal);
            $this->notifyPartner($booking, $partner);

            return $booking->fresh(['unit.images', 'user', 'payment', 'refunds']);
        } catch (\Throwable $e) {
            // Release the idempotency guard so a genuine retry after a failure
            // can proceed (the refund never happened on this path).
            if ($lockKey) {
                Cache::forget($lockKey);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function refundGateway(Payment $payment, float $amount): array
    {
        try {
            return $this->moyasar->refund($payment->moyasar_id, (int) round($amount * 100));
        } catch (\Throwable $e) {
            Log::error('Host-cancel refund failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            throw new DashboardException('REFUND_FAILED', 'تعذّر تنفيذ الاسترداد عبر بوابة الدفع، حاول لاحقاً', 502);
        }
    }

    /** @param array<string, mixed>|null $gatewayResult */
    private function recordRefund(Booking $booking, Payment $payment, float $amount, ?array $gatewayResult): void
    {
        $refund = $booking->refunds()->create([
            'payment_id'        => $payment->id,
            'type'              => Refund::TYPE_REFUND,
            'amount'            => $amount,
            'refund_percent'    => 100,
            'tier_label'        => 'إلغاء المضيف',
            // Webhook flips this to succeeded; test-mode/simulated is immediate.
            'status'            => $gatewayResult ? 'processing' : 'succeeded',
            'moyasar_refund_id' => $gatewayResult['id'] ?? null,
            'moyasar_response'  => $gatewayResult ?? ['test' => true, 'simulated' => true],
        ]);

        $payment->increment('refunded_amount', $amount);

        try {
            $booking->user?->walletTransactions()->create([
                'ref_code'    => 'REF-'.now()->format('Y').'-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT),
                'type'        => \App\Models\WalletTransaction::TYPE_REFUND,
                'amount'      => $amount,
                'description' => 'استرداد كامل — إلغاء المضيف للحجز',
                'status'      => 'completed',
                'booking_id'  => $booking->id,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyGuest(Booking $booking, float $refund): void
    {
        try {
            $booking->loadMissing('user', 'unit');
            $booking->user?->notify(new \App\Notifications\BookingCancelled($booking, $refund, byHost: true));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyPartner(Booking $booking, User $partner): void
    {
        try {
            $partner->notify(new HostCancellation($booking));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function isTestMode(): bool
    {
        return blank(config('moyasar.secret_key'));
    }
}
