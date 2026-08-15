<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerWallet;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of partner balances — wallet contract §2 and §5.
 *
 * Every mutation goes through {@see post()}: one row-locked transaction that
 * appends a ledger row and moves the wallet by the same amount, so the ledger
 * and the balance cannot disagree. Nothing else may write `partner_wallets`.
 */
class PartnerWalletService
{
    /**
     * Append one ledger row and apply it to the balance.
     *
     * The wallet row is locked FOR UPDATE first: two concurrent postings would
     * otherwise both read the same starting balance and the second would write
     * a `balance_after` that silently overwrites the first.
     */
    public function post(
        int $partnerUserId,
        string $type,
        float $amount,
        string $refType,
        ?string $refId = null,
        ?string $refCode = null,
        ?string $description = null,
        ?int $adminId = null,
    ): PartnerLedgerEntry {
        return DB::transaction(function () use (
            $partnerUserId, $type, $amount, $refType, $refId, $refCode, $description, $adminId
        ) {
            $wallet = PartnerWallet::query()
                ->where('partner_user_id', $partnerUserId)
                ->lockForUpdate()
                ->first()
                ?? PartnerWallet::create(['partner_user_id' => $partnerUserId]);

            $amount  = round($amount, 2);
            $balance = round($wallet->available_balance + $amount, 2);

            $entry = PartnerLedgerEntry::create([
                'partner_user_id'     => $partnerUserId,
                'type'                => $type,
                'amount'              => $amount,
                'balance_after'       => $balance,
                'ref_type'            => $refType,
                'ref_id'              => $refId,
                'ref_code'            => $refCode,
                'description'         => $description,
                'created_by_admin_id' => $adminId,
                'created_at'          => now(),
            ]);

            $wallet->available_balance = $balance;

            // Lifetime totals are cumulative and never decrease: an earning adds
            // to lifetime_earnings, a payout adds its magnitude to
            // lifetime_paid_out. A reversal is corrected on the payout side (the
            // row leaves the `paid` set), not by rewinding the total here.
            if ($type === PartnerLedgerEntry::TYPE_EARNING) {
                $wallet->lifetime_earnings = round($wallet->lifetime_earnings + $amount, 2);
            }

            if ($type === PartnerLedgerEntry::TYPE_PAYOUT) {
                $wallet->lifetime_paid_out = round($wallet->lifetime_paid_out + abs($amount), 2);
            }

            $wallet->save();

            return $entry;
        });
    }

    /**
     * Credit a completed booking's partner share — contract §5: the earning
     * lands when the guest checks OUT, not when they book or pay.
     *
     * Idempotent by (booking, earning): a booking re-saved as completed, a
     * re-run of bookings:complete, or a manual status flip must never pay
     * twice. Returns null when the booking has already been credited or has
     * nothing to credit.
     */
    public function recordEarning(Booking $booking): ?PartnerLedgerEntry
    {
        $partnerId = $booking->unit?->user_id;
        $share     = round((float) ($booking->partner_share ?? 0), 2);

        if (! $partnerId || $share <= 0 || $this->alreadyEarned($booking)) {
            return null;
        }

        $unitName = $booking->unit?->unit_name ?: 'وحدة';
        $code     = $booking->code ?: (string) $booking->id;

        return $this->post(
            partnerUserId: $partnerId,
            type: PartnerLedgerEntry::TYPE_EARNING,
            amount: $share,
            refType: 'booking',
            refId: (string) $booking->id,
            refCode: $code,
            description: "حصتك من الحجز {$code} — {$unitName}",
        );
    }

    public function alreadyEarned(Booking $booking): bool
    {
        return PartnerLedgerEntry::query()
            ->where('type', PartnerLedgerEntry::TYPE_EARNING)
            ->where('ref_type', 'booking')
            ->where('ref_id', (string) $booking->id)
            ->exists();
    }

    /** Debit an executed transfer — written after the money has already left. */
    public function recordPayout(Payout $payout): PartnerLedgerEntry
    {
        return $this->post(
            partnerUserId: $payout->partner_user_id,
            type: PartnerLedgerEntry::TYPE_PAYOUT,
            amount: -abs($payout->amount),
            refType: 'payout',
            refId: (string) $payout->id,
            refCode: $payout->reference,
            description: "تحويل بنكي {$payout->reference}",
        );
    }

    /**
     * A bounced transfer: the money comes back to the balance as an adjustment
     * credit while the payout record survives as `reversed` (contract §3).
     */
    public function recordPayoutReversal(Payout $payout, ?string $reason = null): PartnerLedgerEntry
    {
        return $this->post(
            partnerUserId: $payout->partner_user_id,
            type: PartnerLedgerEntry::TYPE_ADJUSTMENT,
            amount: abs($payout->amount),
            refType: 'payout',
            refId: (string) $payout->id,
            refCode: $payout->reference,
            description: $reason ?: "عكس التحويل {$payout->reference}",
        );
    }

    /**
     * Money earned but not yet transferable: paid/confirmed bookings whose stay
     * has not finished (contract §5). Computed from the bookings themselves
     * rather than stored, so it cannot drift out of step with them.
     */
    public function pendingBalance(int $partnerUserId): float
    {
        return round((float) Booking::query()
            ->whereHas('unit', fn ($q) => $q->where('user_id', $partnerUserId))
            ->where('status', Booking::STATUS_CONFIRMED)
            ->sum('partner_share'), 2);
    }
}
